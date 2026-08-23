wit_bindgen::generate!({
    path: "../../plugin-api/wit/broadcast.wit",
    world: "broadcast-world",
});

use exports::stashd::plugin::broadcast_plugin::{
    Artifact, Choice, Error, FinalizationRequest, Guest, Item, OperationRequest, OperationResult,
    OptionValue, PluginError, Preparation, Publication, PublishRequest, Setting,
};
use stashd::plugin::broadcast_host::{self, HttpMethod};

struct JellyfinBroadcast;

impl Guest for JellyfinBroadcast {
    fn prepare(_request: PublishRequest) -> Result<Preparation, PluginError> {
        Ok(Preparation { artifacts: vec![] })
    }

    fn publish(request: PublishRequest) -> Result<Publication, PluginError> {
        let files = request
            .items
            .iter()
            .filter_map(|item| {
                let resource = item
                    .resources
                    .iter()
                    .find(|resource| resource.kind == "video")?;
                let season = "Season 01";
                let episode = format!(
                    "S01E{:02} - {}.mp4",
                    item_index(item, &request.items),
                    sanitize(&item.title)
                );
                Some(broadcast_host::PublishedFile {
                    item_id: item.id.clone(),
                    source_reference: resource.reference.clone(),
                    relative_path: format!("{season}/{episode}"),
                })
            })
            .collect();

        broadcast_host::report_progress("published");
        Ok(Publication {
            artifact: Artifact {
                reference: String::new(),
                media_type: None,
                size_bytes: 0,
            },
            files,
            published_metadata: vec![],
        })
    }

    fn finalize(request: FinalizationRequest) -> Result<Publication, PluginError> {
        let http = broadcast_host::open_http_client();
        let server = setting_text(&request.request.settings, "server_url")
            .ok_or_else(|| failed("Jellyfin server URL is not configured", false))?;
        let credential = setting_text(&request.request.settings, "credential_name")
            .unwrap_or_else(|| "jellyfin-api-token".to_owned());
        let response = broadcast_host::HttpClient::request(
            &http,
            &broadcast_host::HttpRequest {
                method: HttpMethod::Post,
                url: format!("{}/Library/Refresh", server.trim_end_matches('/')),
                credential: Some(credential),
                headers: vec![],
                body: vec![],
            },
        )
        .map_err(|error| failed(&format!("Jellyfin refresh failed: {error:?}"), true))?;
        if response.status < 200 || response.status >= 300 {
            return Err(failed(
                &format!("Jellyfin refresh returned HTTP {}", response.status),
                response.status >= 500,
            ));
        }

        broadcast_host::report_progress("remote refresh complete");
        Ok(request.publication)
    }

    fn operation(request: OperationRequest) -> Result<OperationResult, PluginError> {
        let http = broadcast_host::open_http_client();
        let server = setting_text(&request.settings, "server_url")
            .ok_or_else(|| failed("Jellyfin server URL is not configured", false))?;
        let credential = setting_text(&request.settings, "credential_name")
            .unwrap_or_else(|| "jellyfin-api-token".to_owned());
        let path = match request.name.as_str() {
            "test-connection" => "/System/Info/Public",
            "discover-libraries" => "/Library/MediaFolders",
            "refresh-library" => "/Library/Refresh",
            _ => return Err(failed("Unsupported external operation", false)),
        };
        let method = if request.name == "refresh-library" {
            HttpMethod::Post
        } else {
            HttpMethod::Get
        };
        let response = broadcast_host::HttpClient::request(
            &http,
            &broadcast_host::HttpRequest {
                method,
                url: format!("{}{}", server.trim_end_matches('/'), path),
                credential: Some(credential),
                headers: vec![],
                body: vec![],
            },
        )
        .map_err(|error| failed(&format!("Jellyfin request failed: {error:?}"), true))?;
        if response.status < 200 || response.status >= 300 {
            return Err(failed(
                &format!("Jellyfin request returned HTTP {}", response.status),
                response.status >= 500,
            ));
        }
        if request.name == "refresh-library" {
            return Ok(OperationResult {
                choices: vec![],
                values: vec![text_setting("ok", "true")],
            });
        }
        let json: serde_json::Value = serde_json::from_slice(&response.body)
            .map_err(|_| failed("Jellyfin returned invalid JSON", false))?;
        if request.name == "test-connection" {
            return Ok(OperationResult {
                choices: vec![],
                values: vec![
                    text_setting("ok", "true"),
                    text_setting("message", "Jellyfin connection OK."),
                    text_setting(
                        "server_name",
                        json["ServerName"].as_str().unwrap_or("Jellyfin"),
                    ),
                    text_setting("version", json["Version"].as_str().unwrap_or("")),
                ],
            });
        }
        let choices = json["Items"]
            .as_array()
            .into_iter()
            .flatten()
            .filter_map(|item| {
                Some(Choice {
                    value: item["Id"].as_str()?.to_owned(),
                    label: item["Name"].as_str().unwrap_or("Library").to_owned(),
                })
            })
            .collect();
        Ok(OperationResult {
            choices,
            values: vec![],
        })
    }
}

fn item_index(item: &Item, items: &[Item]) -> usize {
    items
        .iter()
        .position(|candidate| candidate.id == item.id)
        .map_or(1, |index| index + 1)
}

fn sanitize(value: &str) -> String {
    let sanitized: String = value
        .chars()
        .map(|character| {
            if "/\\:*?\"<>|".contains(character) {
                '_'
            } else {
                character
            }
        })
        .collect();
    sanitized
        .trim()
        .trim_matches('.')
        .chars()
        .take(180)
        .collect()
}

fn setting_text(settings: &[Setting], key: &str) -> Option<String> {
    settings.iter().find_map(|setting| {
        if setting.key != key {
            return None;
        }
        match &setting.value {
            OptionValue::Text(value) => Some(value.clone()),
            OptionValue::Boolean(_) => None,
            OptionValue::Number(_) => None,
        }
    })
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

export!(JellyfinBroadcast);
