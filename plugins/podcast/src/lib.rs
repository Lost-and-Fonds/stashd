wit_bindgen::generate!({
    path: "../../plugin-api/wit/broadcast.wit",
    world: "broadcast-world",
});

use exports::stashd::plugin::broadcast_plugin::{
    Artifact, Episode, Error, Guest, OptionValue, PluginError, Publication, PublishRequest, Setting,
};
use stashd::plugin::broadcast_host;

struct PodcastBroadcast;

impl Guest for PodcastBroadcast {
    fn publish(request: PublishRequest) -> Result<Publication, PluginError> {
        broadcast_host::report_progress("publishing");
        let feed_url = setting_text(&request.settings, "feed_url").unwrap_or_else(|| {
            format!(
                "{}/b/{}/feed.xml",
                request.public_base_url.trim_end_matches('/'),
                request.broadcast_token
            )
        });
        let metadata = Metadata::from_settings(&request.settings);
        let xml = build_feed(&request, &metadata, &feed_url);
        let staging = broadcast_host::open_staging_area();
        let xml = xml.into_bytes();
        let artifact = broadcast_host::StagingArea::write(
            &staging,
            "feed.xml",
            &xml,
            Some("application/rss+xml"),
        )
        .map_err(|error| match error {
            broadcast_host::StagingError::InvalidReference => {
                failed("invalid feed output reference", false)
            }
            broadcast_host::StagingError::Failed(message) => failed(&message, true),
        })?;
        broadcast_host::log("Podcast feed published by external component");
        broadcast_host::report_progress("complete");
        Ok(Publication {
            artifact: Artifact {
                reference: artifact.reference,
                media_type: artifact.media_type,
                size_bytes: artifact.size_bytes,
            },
            published_metadata: vec![text_setting("feed_url", feed_url)],
        })
    }
}

struct Metadata {
    title: String,
    description: String,
    author: Option<String>,
    language: String,
    explicit: bool,
    link: Option<String>,
    image: Option<String>,
    funding: Option<String>,
    complete: bool,
    guid: Option<String>,
}

impl Metadata {
    fn from_settings(settings: &[Setting]) -> Self {
        Self {
            title: setting_text(settings, "title").unwrap_or_else(|| "Stashd Podcast".to_owned()),
            description: setting_text(settings, "description")
                .unwrap_or_else(|| "Private Stashd podcast feed.".to_owned()),
            author: setting_text(settings, "author"),
            language: setting_text(settings, "language").unwrap_or_else(|| "en".to_owned()),
            explicit: setting_bool(settings, "explicit"),
            link: setting_text(settings, "link_url"),
            image: setting_text(settings, "image_url"),
            funding: setting_text(settings, "funding_url"),
            complete: setting_bool(settings, "complete"),
            guid: setting_text(settings, "podcast_guid"),
        }
    }
}

fn build_feed(request: &PublishRequest, metadata: &Metadata, feed_url: &str) -> String {
    let mut xml = String::from(
        r#"<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:podcast="https://podcastindex.org/namespace/1.0">
<channel>"#,
    );
    tag(&mut xml, "title", &metadata.title);
    tag(&mut xml, "description", &metadata.description);
    tag(&mut xml, "language", &metadata.language);
    xml.push_str("<atom:link href=\"");
    xml.push_str(&escape(feed_url));
    xml.push_str("\" rel=\"self\" type=\"application/rss+xml\"/>");
    tag(&mut xml, "itunes:summary", &metadata.description);
    tag(&mut xml, "itunes:block", "yes");
    tag(
        &mut xml,
        "itunes:complete",
        if metadata.complete { "yes" } else { "no" },
    );
    tag(&mut xml, "podcast:medium", "podcast");
    tag(
        &mut xml,
        "podcast:guid",
        metadata.guid.as_deref().unwrap_or(&request.broadcast_id),
    );
    if let Some(author) = &metadata.author {
        tag(&mut xml, "itunes:author", author);
    }
    if let Some(link) = &metadata.link {
        tag(&mut xml, "link", link);
    }
    if let Some(image) = &metadata.image {
        xml.push_str("<itunes:image href=\"");
        xml.push_str(&escape(image));
        xml.push_str("\"/>");
    }
    if let Some(funding) = &metadata.funding {
        xml.push_str("<podcast:funding url=\"");
        xml.push_str(&escape(funding));
        xml.push_str("\">Support the creator</podcast:funding>");
    }
    tag(
        &mut xml,
        "itunes:explicit",
        if metadata.explicit { "yes" } else { "no" },
    );
    for episode in &request.episodes {
        episode_xml(&mut xml, request, episode);
    }
    xml.push_str("</channel></rss>");
    xml
}

fn episode_xml(xml: &mut String, request: &PublishRequest, episode: &Episode) {
    xml.push_str("<item>");
    tag(xml, "guid", &episode.id);
    tag(xml, "title", &episode.title);
    if let Some(description) = &episode.description {
        tag(xml, "description", description);
        xml.push_str("<content:encoded>");
        xml.push_str(&cdata(description));
        xml.push_str("</content:encoded>");
    }
    if let Some(published_at) = &episode.published_at {
        tag(xml, "pubDate", published_at);
    }
    let extension = extension(&episode.media_reference);
    let url = episode.media_url.clone().unwrap_or_else(|| {
        format!(
            "{}/b/{}/items/{}/episode.{}",
            request.public_base_url.trim_end_matches('/'),
            request.broadcast_token,
            episode.publication_token,
            extension
        )
    });
    xml.push_str("<enclosure url=\"");
    xml.push_str(&escape(&url));
    xml.push_str("\" length=\"");
    xml.push_str(&episode.media_size_bytes.to_string());
    xml.push_str("\" type=\"");
    xml.push_str(&escape(
        episode.media_type.as_deref().unwrap_or("audio/mpeg"),
    ));
    xml.push_str("\"/>");
    if let Some(seconds) = episode.duration_seconds {
        tag(xml, "itunes:duration", &seconds.to_string());
    }
    if episode.artwork_reference.is_some() {
        let url = episode.artwork_url.as_deref().unwrap_or("");
        xml.push_str("<itunes:image href=\"");
        xml.push_str(&escape(url));
        xml.push_str("\"/>");
    }
    if episode.transcript_reference.is_some() {
        xml.push_str("<podcast:transcript url=\"");
        xml.push_str(&escape(episode.transcript_url.as_deref().unwrap_or("")));
        xml.push_str("\" type=\"text/vtt\"/>");
    }
    if episode.chapter_reference.is_some() {
        xml.push_str("<podcast:chapters url=\"");
        xml.push_str(&escape(episode.chapter_url.as_deref().unwrap_or("")));
        xml.push_str("\" type=\"application/json\"/>");
    }
    xml.push_str("</item>");
}

fn tag(xml: &mut String, name: &str, value: &str) {
    xml.push('<');
    xml.push_str(name);
    xml.push('>');
    xml.push_str(&escape(value));
    xml.push_str("</");
    xml.push_str(name);
    xml.push('>');
}

fn escape(value: &str) -> String {
    value
        .replace('&', "&amp;")
        .replace('<', "&lt;")
        .replace('>', "&gt;")
        .replace('"', "&quot;")
        .replace('\'', "&apos;")
}

fn cdata(value: &str) -> String {
    format!("<![CDATA[{}]]>", value.replace("]]>", "]]]]><![CDATA[>"))
}

fn extension(reference: &str) -> &str {
    reference
        .rsplit_once('.')
        .map(|(_, ext)| ext)
        .unwrap_or("mp3")
}

fn setting_text(settings: &[Setting], key: &str) -> Option<String> {
    settings.iter().find_map(|setting| {
        (setting.key == key).then(|| match &setting.value {
            OptionValue::Text(value) => Some(value.clone()),
            OptionValue::Boolean(_) => None,
        })?
    })
}

fn setting_bool(settings: &[Setting], key: &str) -> bool {
    settings
        .iter()
        .find_map(|setting| {
            (setting.key == key).then_some(match setting.value {
                OptionValue::Boolean(value) => Some(value),
                OptionValue::Text(_) => None,
            })?
        })
        .unwrap_or(false)
}

fn text_setting(key: &str, value: String) -> Setting {
    Setting {
        key: key.to_owned(),
        value: OptionValue::Text(value),
    }
}

fn failed(message: &str, retryable: bool) -> PluginError {
    PluginError::Failed(Error {
        message: message.to_owned(),
        retryable,
    })
}

export!(PodcastBroadcast);
