wit_bindgen::generate!({
    path: "../../plugin-api/wit",
    world: "input-world",
});

use exports::stashd::plugin::input_plugin::{
    AcquisitionOptions, AcquisitionResult, DiscoveredItem, DiscoveryIntent, Error, Guest,
    MediaKind, PluginError, ResolvedInput,
};
use serde_json::Value;
use stashd::plugin::input_host::{self, ArtifactRole};

struct YouTubeInput;

fn error(message: &str, retryable: bool) -> Error {
    Error {
        message: message.to_owned(),
        retryable,
    }
}

fn unsupported(message: &str) -> PluginError {
    PluginError::Unsupported(error(message, false))
}

fn not_found(message: &str) -> PluginError {
    PluginError::NotFound(error(message, false))
}

fn authentication(message: &str, retryable: bool) -> PluginError {
    PluginError::Authentication(error(message, retryable))
}

fn unavailable(message: &str, retryable: bool) -> PluginError {
    PluginError::Unavailable(error(message, retryable))
}

fn invalid_data(message: &str) -> PluginError {
    PluginError::InvalidData(error(message, false))
}

fn failed(message: &str, retryable: bool) -> PluginError {
    PluginError::Failed(error(message, retryable))
}

impl Guest for YouTubeInput {
    fn resolve(source: String) -> Result<ResolvedInput, PluginError> {
        let path = source
            .strip_prefix("https://www.youtube.com")
            .ok_or_else(|| unsupported("YouTube channel reference required"))?;
        let direct_id = if let Some(id) = path.strip_prefix("/channel/") {
            let id = id.split('/').next().unwrap_or_default();
            Some(id.to_owned())
        } else if path.starts_with("/@") || path.starts_with("/c/") || path.starts_with("/user/") {
            None
        } else {
            return Err(unsupported("channel reference required"));
        };

        let (channel_id, title, avatar) = if let Some(id) = direct_id {
            (id, None, None)
        } else {
            input_host::report_progress(&input_host::Progress {
                stage: "resolving channel".to_owned(),
            });
            let client = input_host::open_http_client();
            let response = get(&client, &source)?;
            if response.status == 404 {
                return Err(not_found("channel page not found"));
            }
            if response.status < 200 || response.status >= 300 {
                return Err(unavailable("channel page unavailable", true));
            }
            let html = String::from_utf8(response.body)
                .map_err(|_| invalid_data("channel page was not text"))?;
            let id = extract_channel_id(&html)
                .ok_or_else(|| invalid_data("channel identity was not found"))?;
            (
                id,
                extract_meta(&html, "og:title"),
                extract_meta(&html, "og:image"),
            )
        };

        input_host::log("YouTube channel resolved");
        Ok(ResolvedInput {
            id: channel_id.clone(),
            canonical_reference: Some(format!("https://www.youtube.com/channel/{channel_id}")),
            kind: Some("channel".to_owned()),
            title,
            artwork_reference: avatar,
            estimated_item_count: None,
        })
    }

    fn discover(
        input_id: String,
        intent: DiscoveryIntent,
    ) -> Result<Vec<DiscoveredItem>, PluginError> {
        if matches!(intent, DiscoveryIntent::Complete) {
            let client = input_host::open_http_client();
            return discover_data_api(&client, &input_id);
        }
        input_host::report_progress(&input_host::Progress {
            stage: "fetching feed".to_owned(),
        });
        let client = input_host::open_http_client();
        let response = get(
            &client,
            &format!("https://www.youtube.com/feeds/videos.xml?channel_id={input_id}"),
        )?;
        if response.status == 404 {
            return Err(not_found("channel feed not found"));
        }
        if response.status < 200 || response.status >= 300 {
            return Err(unavailable("channel feed unavailable", true));
        }
        input_host::report_progress(&input_host::Progress {
            stage: "parsing feed".to_owned(),
        });
        let xml =
            String::from_utf8(response.body).map_err(|_| invalid_data("feed was not text"))?;
        let items = parse_feed(&xml)?;
        input_host::log("YouTube RSS discovery complete");
        input_host::report_progress(&input_host::Progress {
            stage: "complete".to_owned(),
        });
        Ok(items)
    }

    fn acquire(
        item: DiscoveredItem,
        options: AcquisitionOptions,
    ) -> Result<AcquisitionResult, PluginError> {
        input_host::report_progress(&input_host::Progress {
            stage: "preparing acquisition".to_owned(),
        });
        let mut args = vec![
            "--no-playlist".to_owned(),
            "--newline".to_owned(),
            "--no-warnings".to_owned(),
            "--restrict-filenames".to_owned(),
            "--output".to_owned(),
            "stashd-original.%(ext)s".to_owned(),
            "--write-info-json".to_owned(),
            "--write-thumbnail".to_owned(),
        ];
        match options.media_kind {
            MediaKind::Video => args.extend([
                "--format".to_owned(),
                "bestvideo[height<=1080]+bestaudio/best[height<=1080]".to_owned(),
                "--merge-output-format".to_owned(),
                "mp4".to_owned(),
            ]),
            MediaKind::Audio => args.extend([
                "--extract-audio".to_owned(),
                "--audio-format".to_owned(),
                "mp3".to_owned(),
                "--audio-quality".to_owned(),
                "128K".to_owned(),
            ]),
        }
        if options.include_captions {
            args.extend([
                "--write-subs".to_owned(),
                "--sub-format".to_owned(),
                "vtt".to_owned(),
                "--sub-langs".to_owned(),
                options.caption_languages.unwrap_or_else(|| "en".to_owned()),
            ]);
        }
        args.push(item.reference.clone());
        input_host::report_progress(&input_host::Progress {
            stage: "downloading media".to_owned(),
        });
        let staging = input_host::open_staging_area();
        let result =
            input_host::StagingArea::run_helper(&staging, "yt-dlp", &args).map_err(|error| {
                match error {
                    input_host::HelperError::Denied | input_host::HelperError::Unavailable(_) => {
                        unavailable("acquisition helper is unavailable", true)
                    }
                    input_host::HelperError::Failed(_) => failed("acquisition helper failed", true),
                }
            })?;
        if result.exit_code == 124 {
            return Err(failed("acquisition timed out", true));
        }
        if result.exit_code != 0 {
            return Err(failed("acquisition helper failed", true));
        }
        let mut artifacts = Vec::new();
        for file in result.files {
            let (role, media_type) = artifact_kind(&file)
                .ok_or_else(|| invalid_data("acquisition produced an unrecognized artifact"))?;
            artifacts.push(
                input_host::StagingArea::stage(&staging, &file, role, Some(media_type))
                    .map_err(|_| invalid_data("acquisition artifact could not be staged"))?,
            );
        }
        if !artifacts
            .iter()
            .any(|artifact| artifact.role == ArtifactRole::Primary)
        {
            return Err(invalid_data(
                "acquisition produced no primary media artifact",
            ));
        }
        input_host::report_progress(&input_host::Progress {
            stage: "finalizing artifacts".to_owned(),
        });
        input_host::log("YouTube acquisition complete");
        input_host::report_progress(&input_host::Progress {
            stage: "complete".to_owned(),
        });
        Ok(AcquisitionResult { artifacts })
    }
}

fn artifact_kind(file: &str) -> Option<(ArtifactRole, &'static str)> {
    let lower = file.to_ascii_lowercase();
    if lower.ends_with(".info.json") {
        Some((ArtifactRole::Metadata, "application/json"))
    } else if lower.ends_with(".vtt") {
        Some((ArtifactRole::Captions, "text/vtt"))
    } else if [".jpg", ".jpeg", ".png", ".webp"]
        .iter()
        .any(|extension| lower.ends_with(extension))
    {
        Some((ArtifactRole::Artwork, "image/*"))
    } else if [".mp4", ".mkv", ".webm"]
        .iter()
        .any(|extension| lower.ends_with(extension))
    {
        Some((ArtifactRole::Primary, "video/*"))
    } else if lower.ends_with(".mp3") || lower.ends_with(".m4a") || lower.ends_with(".opus") {
        Some((ArtifactRole::Primary, "audio/*"))
    } else {
        None
    }
}

fn get(
    client: &input_host::HttpClient,
    url: &str,
) -> Result<input_host::HttpResponse, PluginError> {
    input_host::HttpClient::get(
        client,
        &input_host::HttpRequest {
            url: url.to_owned(),
            credential: None,
        },
    )
    .map_err(|http_error| match http_error {
        input_host::HttpError::Denied => unavailable("HTTP capability denied request", false),
        input_host::HttpError::CredentialUnavailable => {
            authentication("required credential is unavailable", false)
        }
        input_host::HttpError::AuthenticationRejected => {
            authentication("upstream authentication was rejected", false)
        }
        input_host::HttpError::RateLimited => {
            PluginError::RateLimited(error("upstream request was rate limited", true))
        }
        input_host::HttpError::Unavailable(message) | input_host::HttpError::Failed(message) => {
            unavailable(&message, true)
        }
    })
}

fn data_api_get(client: &input_host::HttpClient, url: &str) -> Result<Value, PluginError> {
    input_host::report_progress(&input_host::Progress {
        stage: "fetching Data API".to_owned(),
    });
    let response = input_host::HttpClient::get(
        client,
        &input_host::HttpRequest {
            url: url.to_owned(),
            credential: Some("youtube-data-api".to_owned()),
        },
    )
    .map_err(|http_error| match http_error {
        input_host::HttpError::Denied => unavailable("HTTP capability denied request", false),
        input_host::HttpError::CredentialUnavailable => {
            authentication("required credential is unavailable", false)
        }
        input_host::HttpError::AuthenticationRejected => {
            authentication("upstream authentication was rejected", false)
        }
        input_host::HttpError::RateLimited => {
            PluginError::RateLimited(error("upstream request was rate limited", true))
        }
        input_host::HttpError::Unavailable(_) | input_host::HttpError::Failed(_) => {
            unavailable("upstream service is unavailable", true)
        }
    })?;

    match response.status {
        401 | 403 => {
            return Err(authentication(
                "upstream authentication was rejected",
                false,
            ));
        }
        404 => {
            return Err(not_found("upstream resource was not found"));
        }
        429 => {
            return Err(PluginError::RateLimited(error(
                "upstream request was rate limited",
                true,
            )));
        }
        status if !(200..300).contains(&status) => {
            return Err(unavailable("upstream request failed", true));
        }
        _ => {}
    }

    serde_json::from_slice(&response.body)
        .map_err(|_| invalid_data("upstream response was invalid"))
}

fn discover_data_api(
    client: &input_host::HttpClient,
    channel_id: &str,
) -> Result<Vec<DiscoveredItem>, PluginError> {
    input_host::report_progress(&input_host::Progress {
        stage: "resolving uploads playlist".to_owned(),
    });
    let channel = data_api_get(
        client,
        &format!(
            "https://www.googleapis.com/youtube/v3/channels?id={channel_id}&part=contentDetails"
        ),
    )?;
    let uploads = channel
        .get("items")
        .and_then(Value::as_array)
        .and_then(|items| items.first())
        .and_then(|item| item.get("contentDetails"))
        .and_then(|details| details.get("relatedPlaylists"))
        .and_then(|playlists| playlists.get("uploads"))
        .and_then(Value::as_str)
        .filter(|id| !id.is_empty())
        .ok_or_else(|| not_found("input collection was not found"))?
        .to_owned();

    let mut entries = Vec::new();
    let mut page_token: Option<String> = None;
    loop {
        let mut url = format!(
            "https://www.googleapis.com/youtube/v3/playlistItems?playlistId={uploads}&part=snippet&maxResults=50"
        );
        if let Some(token) = &page_token {
            url.push_str("&pageToken=");
            url.push_str(token);
        }
        input_host::report_progress(&input_host::Progress {
            stage: format!("fetching playlist page {}", entries.len() / 50 + 1),
        });
        let payload = data_api_get(client, &url)?;
        if let Some(items) = payload.get("items").and_then(Value::as_array) {
            for item in items {
                let snippet = item.get("snippet");
                let video_id = snippet
                    .and_then(|value| value.get("resourceId"))
                    .and_then(|value| value.get("videoId"))
                    .and_then(Value::as_str);
                if let Some(video_id) = video_id.filter(|id| !id.is_empty()) {
                    entries.push(PlaylistEntry {
                        video_id: video_id.to_owned(),
                        title: string_field(snippet, "title")
                            .unwrap_or_else(|| video_id.to_owned()),
                        description: string_field(snippet, "description"),
                        published_at: string_field(snippet, "publishedAt"),
                        artwork_reference: best_thumbnail(
                            snippet.and_then(|value| value.get("thumbnails")),
                        ),
                    });
                }
            }
        }
        page_token = payload
            .get("nextPageToken")
            .and_then(Value::as_str)
            .map(str::to_owned);
        if page_token.is_none() {
            break;
        }
    }

    input_host::report_progress(&input_host::Progress {
        stage: "fetching video details".to_owned(),
    });
    let mut output = Vec::new();
    for batch in entries.chunks(50) {
        let ids = batch
            .iter()
            .map(|entry| entry.video_id.as_str())
            .collect::<Vec<_>>()
            .join(",");
        let payload = data_api_get(
            client,
            &format!(
                "https://www.googleapis.com/youtube/v3/videos?id={ids}&part=snippet,contentDetails,liveStreamingDetails"
            ),
        )?;
        let details = payload
            .get("items")
            .and_then(Value::as_array)
            .cloned()
            .unwrap_or_default();
        for entry in batch {
            let detail = details.iter().find(|item| {
                item.get("id").and_then(Value::as_str) == Some(entry.video_id.as_str())
            });
            let Some(detail) = detail else { continue };
            let (duration_seconds, kind) = classify(detail);
            output.push(DiscoveredItem {
                id: entry.video_id.clone(),
                reference: format!("https://www.youtube.com/watch?v={}", entry.video_id),
                title: entry.title.clone(),
                description: entry.description.clone(),
                published_at: entry.published_at.clone(),
                artwork_reference: entry.artwork_reference.clone(),
                duration_seconds,
                kind: Some(kind),
            });
        }
    }
    input_host::report_progress(&input_host::Progress {
        stage: "complete".to_owned(),
    });
    input_host::log("YouTube Data API discovery complete");
    Ok(output)
}

struct PlaylistEntry {
    video_id: String,
    title: String,
    description: Option<String>,
    published_at: Option<String>,
    artwork_reference: Option<String>,
}

fn string_field(value: Option<&Value>, field: &str) -> Option<String> {
    value
        .and_then(|value| value.get(field))
        .and_then(Value::as_str)
        .map(str::to_owned)
}

fn best_thumbnail(value: Option<&Value>) -> Option<String> {
    ["maxres", "standard", "high", "medium", "default"]
        .into_iter()
        .find_map(|size| string_field(value.and_then(|value| value.get(size)), "url"))
}

fn classify(item: &Value) -> (Option<u32>, String) {
    let duration = string_field(item.get("contentDetails"), "duration").and_then(parse_duration);
    let live = string_field(item.get("snippet"), "liveBroadcastContent");
    let content_type = match live.as_deref() {
        Some("live") => "live",
        Some("upcoming") => "premiere",
        _ if duration.is_some_and(|seconds| seconds <= 180) => "short",
        _ => "regular",
    };
    (duration, content_type.to_owned())
}

fn parse_duration(value: String) -> Option<u32> {
    let mut number = String::new();
    let mut seconds = 0u32;
    for character in value.strip_prefix("PT")?.chars() {
        if character.is_ascii_digit() {
            number.push(character);
            continue;
        }
        let amount = number.parse::<u32>().ok()?;
        number.clear();
        seconds = seconds.checked_add(match character {
            'H' => amount.checked_mul(3600)?,
            'M' => amount.checked_mul(60)?,
            'S' => amount,
            _ => return None,
        })?;
    }
    Some(seconds)
}

fn extract_channel_id(html: &str) -> Option<String> {
    for marker in [
        "rel=\"canonical\"",
        "property=\"og:url\"",
        "\"externalId\":\"",
    ] {
        if let Some(position) = html.find(marker) {
            let tail = &html[position..];
            if let Some(start) = tail.find("UC") {
                let id: String = tail[start..]
                    .chars()
                    .take_while(|character| {
                        character.is_ascii_alphanumeric() || *character == '-' || *character == '_'
                    })
                    .collect();
                if id.len() >= 24 {
                    return Some(id);
                }
            }
        }
    }
    None
}

fn extract_meta(html: &str, property: &str) -> Option<String> {
    let marker = format!("property=\"{property}\"");
    let position = html.find(&marker)?;
    let tag = &html[position..html[position..].find('>')? + position];
    let start = tag.find("content=\"")? + "content=\"".len();
    let end = tag[start..].find('"')? + start;
    Some(tag[start..end].to_owned())
}

fn local_name(name: &[u8]) -> &[u8] {
    name.rsplit(|byte| *byte == b':').next().unwrap_or(name)
}

fn parse_feed(xml: &str) -> Result<Vec<DiscoveredItem>, PluginError> {
    use quick_xml::events::Event;
    let mut reader = quick_xml::Reader::from_str(xml);
    let mut current = String::new();
    let mut id = String::new();
    let mut title = String::new();
    let mut description = None;
    let mut published = None;
    let mut thumbnail = None;
    let mut canonical = None;
    let mut items = Vec::new();
    let mut root_seen = false;
    loop {
        match reader.read_event() {
            Ok(Event::Start(event)) => {
                let event_name = event.name();
                let name = local_name(event_name.as_ref());
                if !root_seen {
                    if name != b"feed" {
                        return Err(invalid_data("document has no feed root"));
                    }
                    root_seen = true;
                }
                current = String::from_utf8_lossy(name).to_string()
            }
            Ok(Event::Empty(event)) if local_name(event.name().as_ref()) == b"link" => {
                for attribute in event.attributes().flatten() {
                    if attribute.key.as_ref() == b"href" {
                        canonical = Some(String::from_utf8_lossy(&attribute.value).to_string());
                    }
                }
            }
            Ok(Event::Empty(event)) if local_name(event.name().as_ref()) == b"thumbnail" => {
                for attribute in event.attributes().flatten() {
                    if attribute.key.as_ref() == b"url" {
                        thumbnail = Some(String::from_utf8_lossy(&attribute.value).to_string());
                    }
                }
            }
            Ok(Event::Text(event)) => {
                let value = event
                    .unescape()
                    .map_err(|_| invalid_data("document contains invalid text"))?
                    .into_owned();
                let value = value.trim().to_owned();
                match current.as_str() {
                    "videoId" if !value.is_empty() => id = value,
                    "title" if !value.is_empty() => title = value,
                    "description" => {
                        if !value.trim().is_empty() {
                            description = Some(value.trim().to_owned())
                        }
                    }
                    "published" if !value.is_empty() => published = Some(value),
                    _ => {}
                }
            }
            Ok(Event::End(event)) if local_name(event.name().as_ref()) == b"entry" => {
                if !id.is_empty() {
                    items.push(DiscoveredItem {
                        id: id.clone(),
                        reference: canonical
                            .clone()
                            .unwrap_or_else(|| format!("https://www.youtube.com/watch?v={id}")),
                        title: if title.is_empty() {
                            format!("YouTube Video {id}")
                        } else {
                            title.clone()
                        },
                        description: description.clone(),
                        published_at: published.clone(),
                        artwork_reference: thumbnail.clone(),
                        duration_seconds: None,
                        kind: None,
                    });
                }
                id.clear();
                title.clear();
                description = None;
                published = None;
                thumbnail = None;
                canonical = None;
                current.clear();
            }
            Ok(Event::Eof) if root_seen => break,
            Ok(Event::Eof) => {
                return Err(invalid_data("document was empty"));
            }
            Err(_) => {
                return Err(invalid_data("document could not be parsed"));
            }
            _ => {}
        }
    }
    Ok(items)
}

export!(YouTubeInput);
