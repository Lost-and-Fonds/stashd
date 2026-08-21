wit_bindgen::generate!({
    path: "../../plugin-api/wit",
    world: "youtube-input-world",
});

use exports::stashd::plugin::input_plugin::{DiscoveredItem, Guest, PluginError, ResolvedInput};
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
    ) -> Result<Vec<DiscoveredItem>, PluginError> {
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
    input_host::HttpClient::get(client, url).map_err(|error| match error {
        input_host::HttpError::Denied => {
            PluginError::UpstreamUnavailable("HTTP capability denied request".to_owned())
        }
        input_host::HttpError::Unavailable(message) | input_host::HttpError::Failed(message) => {
            PluginError::UpstreamUnavailable(message)
        }
    })
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
