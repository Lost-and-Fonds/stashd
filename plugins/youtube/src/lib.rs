wit_bindgen::generate!({
    path: "../../plugin-api/wit",
    world: "youtube-input-world",
});

use exports::stashd::plugin::input_plugin::{
    DiscoveredItem, DiscoveryMode, Guest, PluginError, ResolvedInput,
};
use serde_json::Value;
use stashd::plugin::input_host::{self, HttpClient};

struct YouTubeInput;

impl Guest for YouTubeInput {
    fn resolve(client: &HttpClient, source_uri: String) -> Result<ResolvedInput, PluginError> {
        let path = source_uri
            .strip_prefix("https://www.youtube.com")
            .ok_or_else(|| {
                PluginError::UnsupportedSource("YouTube channel URL required".to_owned())
            })?;
        let direct_id = if let Some(id) = path.strip_prefix("/channel/") {
            let id = id.split('/').next().unwrap_or_default();
            Some(id.to_owned())
        } else if path.starts_with("/@") || path.starts_with("/c/") || path.starts_with("/user/") {
            None
        } else {
            return Err(PluginError::UnsupportedSource(
                "channel URL required".to_owned(),
            ));
        };

        let (channel_id, title, avatar) = if let Some(id) = direct_id {
            (id, None, None)
        } else {
            input_host::report_progress(&input_host::Progress {
                stage: "resolving channel".to_owned(),
            });
            let response = get(client, &source_uri)?;
            if response.status == 404 {
                return Err(PluginError::SourceNotFound(
                    "channel page not found".to_owned(),
                ));
            }
            if response.status < 200 || response.status >= 300 {
                return Err(PluginError::UpstreamUnavailable(
                    "channel page unavailable".to_owned(),
                ));
            }
            let html = String::from_utf8(response.body).map_err(|_| {
                PluginError::ChannelResolutionFailed("channel page was not UTF-8".to_owned())
            })?;
            let id = extract_channel_id(&html).ok_or_else(|| {
                PluginError::ChannelResolutionFailed("channel ID was not found".to_owned())
            })?;
            (
                id,
                extract_meta(&html, "og:title"),
                extract_meta(&html, "og:image"),
            )
        };

        input_host::log("YouTube channel resolved");
        Ok(ResolvedInput {
            provider_key: "youtube".to_owned(),
            input_type: "channel".to_owned(),
            source_uri,
            canonical_source_uri: format!("https://www.youtube.com/channel/{channel_id}"),
            provider_input_id: channel_id,
            title,
            avatar_uri: avatar,
            estimated_item_count: None,
        })
    }

    fn discover(
        client: &HttpClient,
        channel_id: String,
        mode: DiscoveryMode,
    ) -> Result<Vec<DiscoveredItem>, PluginError> {
        if matches!(mode, DiscoveryMode::DataApi) {
            return discover_data_api(client, &channel_id);
        }
        input_host::report_progress(&input_host::Progress {
            stage: "fetching feed".to_owned(),
        });
        let response = get(
            client,
            &format!("https://www.youtube.com/feeds/videos.xml?channel_id={channel_id}"),
        )?;
        if response.status == 404 {
            return Err(PluginError::SourceNotFound(
                "channel feed not found".to_owned(),
            ));
        }
        if response.status < 200 || response.status >= 300 {
            return Err(PluginError::UpstreamUnavailable(
                "channel feed unavailable".to_owned(),
            ));
        }
        input_host::report_progress(&input_host::Progress {
            stage: "parsing feed".to_owned(),
        });
        let xml = String::from_utf8(response.body)
            .map_err(|_| PluginError::MalformedFeed("feed was not UTF-8".to_owned()))?;
        let items = parse_feed(&xml)?;
        input_host::log("YouTube RSS discovery complete");
        input_host::report_progress(&input_host::Progress {
            stage: "complete".to_owned(),
        });
        Ok(items)
    }
}

fn get(client: &HttpClient, url: &str) -> Result<input_host::HttpResponse, PluginError> {
    input_host::HttpClient::get(
        client,
        &input_host::HttpRequest {
            url: url.to_owned(),
            credential: None,
        },
    )
    .map_err(|error| match error {
        input_host::HttpError::Denied => {
            PluginError::UpstreamUnavailable("HTTP capability denied request".to_owned())
        }
        input_host::HttpError::CredentialUnavailable => {
            PluginError::CredentialUnavailable("credential use was not granted".to_owned())
        }
        input_host::HttpError::AuthenticationRejected => {
            PluginError::AuthenticationRejected("YouTube authentication was rejected".to_owned())
        }
        input_host::HttpError::RateLimited => {
            PluginError::RateLimited("YouTube request was rate limited".to_owned())
        }
        input_host::HttpError::Unavailable(message) | input_host::HttpError::Failed(message) => {
            PluginError::UpstreamUnavailable(message)
        }
    })
}

fn data_api_get(client: &HttpClient, url: &str) -> Result<Value, PluginError> {
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
    .map_err(|error| match error {
        input_host::HttpError::Denied => {
            PluginError::UpstreamUnavailable("HTTP capability denied request".to_owned())
        }
        input_host::HttpError::CredentialUnavailable => PluginError::CredentialUnavailable(
            "YouTube Data API credential is unavailable".to_owned(),
        ),
        input_host::HttpError::AuthenticationRejected => {
            PluginError::AuthenticationRejected("YouTube authentication was rejected".to_owned())
        }
        input_host::HttpError::RateLimited => {
            PluginError::RateLimited("YouTube request was rate limited".to_owned())
        }
        input_host::HttpError::Unavailable(_) | input_host::HttpError::Failed(_) => {
            PluginError::UpstreamUnavailable("YouTube Data API is unavailable".to_owned())
        }
    })?;

    match response.status {
        401 | 403 => {
            return Err(PluginError::AuthenticationRejected(
                "YouTube authentication was rejected".to_owned(),
            ));
        }
        404 => {
            return Err(PluginError::SourceNotFound(
                "YouTube Data API resource was not found".to_owned(),
            ));
        }
        429 => {
            return Err(PluginError::RateLimited(
                "YouTube Data API quota was rate limited".to_owned(),
            ));
        }
        status if !(200..300).contains(&status) => {
            return Err(PluginError::UpstreamUnavailable(
                "YouTube Data API request failed".to_owned(),
            ));
        }
        _ => {}
    }

    serde_json::from_slice(&response.body).map_err(|_| {
        PluginError::MalformedApiResponse("YouTube Data API returned invalid JSON".to_owned())
    })
}

fn discover_data_api(
    client: &HttpClient,
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
        .ok_or_else(|| {
            PluginError::SourceNotFound("channel uploads playlist was not found".to_owned())
        })?
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
                        thumbnail_uri: best_thumbnail(
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
            let (duration_seconds, content_type) = classify(detail);
            output.push(DiscoveredItem {
                provider_item_id: entry.video_id.clone(),
                canonical_uri: format!("https://www.youtube.com/watch?v={}", entry.video_id),
                title: entry.title.clone(),
                description: entry.description.clone(),
                published_at: entry.published_at.clone(),
                thumbnail_uri: entry.thumbnail_uri.clone(),
                duration_seconds,
                content_type: Some(content_type),
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
    thumbnail_uri: Option<String>,
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
                        return Err(PluginError::MalformedFeed(
                            "RSS document has no Atom feed root".to_owned(),
                        ));
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
                    .map_err(|_| PluginError::MalformedFeed("invalid XML text".to_owned()))?
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
                        provider_item_id: id.clone(),
                        canonical_uri: canonical
                            .clone()
                            .unwrap_or_else(|| format!("https://www.youtube.com/watch?v={id}")),
                        title: if title.is_empty() {
                            format!("YouTube Video {id}")
                        } else {
                            title.clone()
                        },
                        description: description.clone(),
                        published_at: published.clone(),
                        thumbnail_uri: thumbnail.clone(),
                        duration_seconds: None,
                        content_type: None,
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
                return Err(PluginError::MalformedFeed(
                    "RSS document was empty".to_owned(),
                ));
            }
            Err(_) => {
                return Err(PluginError::MalformedFeed(
                    "RSS XML could not be parsed".to_owned(),
                ));
            }
            _ => {}
        }
    }
    Ok(items)
}

export!(YouTubeInput);
