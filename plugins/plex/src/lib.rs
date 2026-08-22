wit_bindgen::generate!({
    path: "../../plugin-api/wit/broadcast.wit",
    world: "broadcast-world",
});

use exports::stashd::plugin::broadcast_plugin::{
    Artifact, Error, FinalizationRequest, Guest, OperationRequest, OperationResult, OptionValue,
    PluginError, Preparation, Publication, PublishRequest, Setting,
};
use stashd::plugin::broadcast_host::{self, HttpMethod};

struct PlexBroadcast;

impl Guest for PlexBroadcast {
    fn prepare(_request: PublishRequest) -> Result<Preparation, PluginError> {
        Ok(Preparation { artifacts: vec![] })
    }

    fn publish(request: PublishRequest) -> Result<Publication, PluginError> {
        let staging = broadcast_host::open_staging_area();
        let nfo = format!(
            "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n<tvshow>\n  <title>{}</title>\n</tvshow>\n",
            escape(
                setting_text(&request.settings, "title")
                    .as_deref()
                    .unwrap_or("Stashd Library")
            ),
        );
        let artifact = broadcast_host::StagingArea::write(
            &staging,
            "tvshow.nfo",
            nfo.as_bytes(),
            Some("application/xml"),
        )
        .map_err(|error| failed(&format!("Plex metadata staging failed: {error:?}"), true))?;

        let mut files = Vec::new();
        for (index, item) in request.items.iter().enumerate() {
            let Some(video) = item
                .resources
                .iter()
                .find(|resource| resource.kind == "video")
            else {
                continue;
            };
            let extension = video
                .media_type
                .as_deref()
                .and_then(media_extension)
                .unwrap_or("mp4");
            let title = sanitize(&item.title);
            files.push(broadcast_host::PublishedFile {
                item_id: item.id.clone(),
                source_reference: video.reference.clone(),
                relative_path: format!("Season 01/S01E{:03} - {title}.{extension}", index + 1),
            });

            if setting_text(&request.settings, "captions").as_deref() != Some("off")
                && let Some(subtitle) = item
                    .resources
                    .iter()
                    .find(|resource| resource.kind == "subtitle")
            {
                let language = setting_text(&request.settings, "caption_languages")
                    .and_then(|value| {
                        value
                            .split(',')
                            .next()
                            .map(str::trim)
                            .filter(|value| !value.is_empty())
                            .map(str::to_owned)
                    })
                    .unwrap_or_else(|| "und".to_owned());
                files.push(broadcast_host::PublishedFile {
                    item_id: item.id.clone(),
                    source_reference: subtitle.reference.clone(),
                    relative_path: format!(
                        "Season 01/S01E{:03} - {title}.{language}.vtt",
                        index + 1
                    ),
                });
            }
        }

        broadcast_host::report_progress("published");
        Ok(Publication {
            artifact: Artifact {
                reference: artifact.reference,
                media_type: artifact.media_type,
                size_bytes: artifact.size_bytes,
            },
            files,
            published_metadata: vec![],
        })
    }

    fn finalize(request: FinalizationRequest) -> Result<Publication, PluginError> {
        let server = setting_text(&request.request.settings, "server_url")
            .ok_or_else(|| failed("Plex server URL is not configured", false))?;
        let library = setting_text_any(&request.request.settings, &["library_id", "libraryId"])
            .ok_or_else(|| failed("Plex library is not configured", false))?;
        refresh(&server, &library, &request.request.settings)?;
        broadcast_host::report_progress("remote refresh complete");
        Ok(request.publication)
    }

    fn operation(request: OperationRequest) -> Result<OperationResult, PluginError> {
        let server = setting_text(&request.settings, "server_url")
            .ok_or_else(|| failed("Plex server URL is not configured", false))?;
        let credential = setting_text(&request.settings, "credential_name")
            .unwrap_or_else(|| "plex-api-token".to_owned());
        let path = match request.name.as_str() {
            "test-connection" => "/identity".to_owned(),
            "discover-libraries" => "/library/sections".to_owned(),
            "refresh-library" => format!(
                "/library/sections/{}/refresh",
                setting_text_any(&request.settings, &["library_id", "libraryId"])
                    .ok_or_else(|| failed("Plex library is not configured", false))?
            ),
            _ => return Err(failed("Unsupported external operation", false)),
        };
        let response = request_http(&server, &path, &credential, HttpMethod::Get)?;
        if response.status < 200 || response.status >= 300 {
            return Err(failed(
                &format!("Plex request returned HTTP {}", response.status),
                response.status >= 500,
            ));
        }
        if request.name == "test-connection" {
            return Ok(OperationResult {
                choices: vec![],
                values: vec![
                    text_setting("ok", "true"),
                    text_setting("message", "Plex connection OK."),
                    text_setting("server_name", "Plex"),
                ],
            });
        }
        if request.name == "refresh-library" {
            return Ok(OperationResult {
                choices: vec![],
                values: vec![text_setting("ok", "true")],
            });
        }
        Ok(OperationResult {
            choices: xml_directories(&response.body),
            values: vec![],
        })
    }
}

fn refresh(server: &str, library: &str, settings: &[Setting]) -> Result<(), PluginError> {
    let credential =
        setting_text(settings, "credential_name").unwrap_or_else(|| "plex-api-token".to_owned());
    let response = request_http(
        server,
        &format!("/library/sections/{library}/refresh"),
        &credential,
        HttpMethod::Get,
    )?;
    if response.status < 200 || response.status >= 300 {
        return Err(failed(
            &format!("Plex refresh returned HTTP {}", response.status),
            response.status >= 500,
        ));
    }
    Ok(())
}

fn request_http(
    server: &str,
    path: &str,
    credential: &str,
    method: HttpMethod,
) -> Result<broadcast_host::HttpResponse, PluginError> {
    let http = broadcast_host::open_http_client();
    broadcast_host::HttpClient::request(
        &http,
        &broadcast_host::HttpRequest {
            method,
            url: format!("{}{}", server.trim_end_matches('/'), path),
            credential: Some(credential.to_owned()),
            headers: vec![],
            body: vec![],
        },
    )
    .map_err(|error| failed(&format!("Plex request failed: {error:?}"), true))
}

fn xml_directories(body: &[u8]) -> Vec<exports::stashd::plugin::broadcast_plugin::Choice> {
    let text = String::from_utf8_lossy(body);
    text.split("<Directory")
        .skip(1)
        .filter_map(|entry| {
            let value = attribute(entry, "key")?;
            let label = attribute(entry, "title").unwrap_or_else(|| "Library".to_owned());
            Some(exports::stashd::plugin::broadcast_plugin::Choice { value, label })
        })
        .collect()
}

fn attribute(value: &str, name: &str) -> Option<String> {
    let marker = format!("{name}=\"");
    let start = value.find(&marker)? + marker.len();
    Some(value[start..].split('"').next()?.to_owned())
}

fn media_extension(media_type: &str) -> Option<&'static str> {
    match media_type {
        "video/mp4" => Some("mp4"),
        "video/webm" => Some("webm"),
        _ => None,
    }
}

fn sanitize(value: &str) -> String {
    let value: String = value
        .chars()
        .map(|character| {
            if "/\\:*?\"<>|".contains(character) {
                '_'
            } else {
                character
            }
        })
        .collect();
    let value = value.trim().trim_matches('.');
    if value.is_empty() {
        "untitled".to_owned()
    } else {
        value.chars().take(180).collect()
    }
}

fn escape(value: &str) -> String {
    value
        .replace('&', "&amp;")
        .replace('<', "&lt;")
        .replace('>', "&gt;")
        .replace('"', "&quot;")
}

fn setting_text(settings: &[Setting], key: &str) -> Option<String> {
    settings
        .iter()
        .find_map(|setting| match (&setting.key[..], &setting.value) {
            (candidate, OptionValue::Text(value)) if candidate == key => Some(value.clone()),
            _ => None,
        })
}

fn setting_text_any(settings: &[Setting], keys: &[&str]) -> Option<String> {
    keys.iter().find_map(|key| setting_text(settings, key))
}

fn text_setting(key: &str, value: &str) -> Setting {
    Setting {
        key: key.to_owned(),
        value: OptionValue::Text(value.to_owned()),
    }
}

fn failed(message: &str, retryable: bool) -> PluginError {
    PluginError::Unavailable(Error {
        message: message.to_owned(),
        retryable,
    })
}

export!(PlexBroadcast);
