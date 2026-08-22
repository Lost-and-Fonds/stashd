wit_bindgen::generate!({
    path: "../../plugin-api/wit/broadcast.wit",
    world: "broadcast-world",
});

use exports::stashd::plugin::broadcast_plugin::{
    Artifact, DerivedArtifact, Error, FinalizationRequest, Guest, Item, OperationRequest,
    OperationResult, OptionValue, PluginError, Preparation, Publication, PublishRequest, Setting,
};
use stashd::plugin::broadcast_host;

struct PodcastBroadcast;

const PODCAST_AUDIO_DERIVATION_KEY: &str = "podcast-audio-v1";

impl Guest for PodcastBroadcast {
    fn prepare(request: PublishRequest) -> Result<Preparation, PluginError> {
        let media_kind =
            setting_text(&request.settings, "media_kind").unwrap_or_else(|| "audio".to_owned());
        if media_kind != "audio" {
            return Ok(Preparation { artifacts: vec![] });
        }

        let staging = broadcast_host::open_staging_area();
        let mut artifacts = Vec::new();
        for item in &request.items {
            if preferred_audio(item).is_some() {
                continue;
            }
            let Some(video) = item
                .resources
                .iter()
                .find(|resource| resource.kind == "video")
            else {
                continue;
            };
            let output = format!("derived-{}.mp3", item.id);
            broadcast_host::report_progress("deriving audio");
            let arguments = vec![
                "-nostdin".to_owned(),
                "-y".to_owned(),
                "-i".to_owned(),
                video.reference.clone(),
                "-vn".to_owned(),
                "-map_metadata".to_owned(),
                "0".to_owned(),
                "-map_chapters".to_owned(),
                "0".to_owned(),
                "-codec:a".to_owned(),
                "libmp3lame".to_owned(),
                "-b:a".to_owned(),
                "128k".to_owned(),
                "-ac".to_owned(),
                "2".to_owned(),
                "-ar".to_owned(),
                "44100".to_owned(),
                output.clone(),
            ];
            let helper = broadcast_host::StagingArea::run_helper(&staging, "ffmpeg", &arguments)
                .map_err(|error| failed(&format!("audio helper unavailable: {error:?}"), true))?;
            if helper.exit_code != 0 {
                return Err(failed("audio helper failed", true));
            }
            let staged = broadcast_host::StagingArea::stage(&staging, &output, Some("audio/mpeg"))
                .map_err(|error| failed(&format!("audio output unavailable: {error:?}"), true))?;
            artifacts.push(DerivedArtifact {
                item_id: item.id.clone(),
                reference: staged.reference,
                derived_from_reference: video.reference.clone(),
                derivation_key: PODCAST_AUDIO_DERIVATION_KEY.to_owned(),
                kind: "audio".to_owned(),
                media_type: staged.media_type,
                size_bytes: staged.size_bytes,
            });
        }
        Ok(Preparation { artifacts })
    }

    fn publish(request: PublishRequest) -> Result<Publication, PluginError> {
        broadcast_host::report_progress("publishing");
        let feed_url = setting_text(&request.settings, "publication_url")
            .unwrap_or_else(|| "urn:stashd:published-resource".to_owned());
        let metadata = Metadata::from_settings(&request.settings);
        let xml = build_feed(&request, &metadata, &feed_url);
        let staging = broadcast_host::open_staging_area();
        let artifact = broadcast_host::StagingArea::write(
            &staging,
            "feed.xml",
            &xml.into_bytes(),
            Some("application/rss+xml"),
        )
        .map_err(|error| match error {
            broadcast_host::StagingError::InvalidReference => {
                failed("invalid output reference", false)
            }
            broadcast_host::StagingError::Missing => failed("staged output is missing", true),
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
            files: vec![],
            published_metadata: vec![text_setting("publication_url", feed_url)],
        })
    }

    fn operation(_request: OperationRequest) -> Result<OperationResult, PluginError> {
        Err(failed("Unsupported broadcast operation", false))
    }

    fn finalize(request: FinalizationRequest) -> Result<Publication, PluginError> {
        Ok(request.publication)
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
    media_kind: Option<String>,
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
            media_kind: setting_text(settings, "media_kind"),
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
        metadata.guid.as_deref().unwrap_or(&request.reference),
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
    for item in &request.items {
        item_xml(&mut xml, item, metadata);
    }
    xml.push_str("</channel></rss>");
    xml
}

fn item_xml(xml: &mut String, item: &Item, metadata: &Metadata) {
    let media = item
        .resources
        .iter()
        .find(|resource| {
            metadata.media_kind.as_deref() == Some("video") && resource.kind == "video"
        })
        .or_else(|| {
            (metadata.media_kind.as_deref() != Some("video"))
                .then(|| preferred_audio(item))
                .flatten()
        })
        .or_else(|| {
            item.resources
                .iter()
                .find(|resource| resource.kind == "video")
        });
    let Some(media) = media else {
        return;
    };

    xml.push_str("<item>");
    tag(xml, "guid", &item.id);
    tag(xml, "title", &item.title);
    if let Some(description) = &item.description {
        tag(xml, "description", description);
        xml.push_str("<content:encoded>");
        xml.push_str(&cdata(description));
        xml.push_str("</content:encoded>");
    }
    if let Some(published_at) = &item.published_at {
        tag(xml, "pubDate", published_at);
    }
    xml.push_str("<enclosure url=\"");
    xml.push_str(&escape(media.url.as_deref().unwrap_or("")));
    xml.push_str("\" length=\"");
    xml.push_str(&media.size_bytes.to_string());
    xml.push_str("\" type=\"");
    xml.push_str(&escape(media.media_type.as_deref().unwrap_or("audio/mpeg")));
    xml.push_str("\"/>");
    if let Some(seconds) = item.duration_seconds {
        tag(xml, "itunes:duration", &seconds.to_string());
    }
    if let Some(resource) = item
        .resources
        .iter()
        .find(|resource| resource.kind == "image")
    {
        xml.push_str("<itunes:image href=\"");
        xml.push_str(&escape(resource.url.as_deref().unwrap_or("")));
        xml.push_str("\"/>");
    }
    if let Some(resource) = item
        .resources
        .iter()
        .find(|resource| resource.kind == "subtitle")
    {
        xml.push_str("<podcast:transcript url=\"");
        xml.push_str(&escape(resource.url.as_deref().unwrap_or("")));
        xml.push_str("\" type=\"text/vtt\"/>");
    }
    xml.push_str("</item>");
}

fn preferred_audio(
    item: &Item,
) -> Option<&exports::stashd::plugin::broadcast_plugin::ItemResource> {
    item.resources
        .iter()
        .find(|resource| resource.kind == "audio" && resource.derivation_key.is_none())
        .or_else(|| {
            item.resources.iter().find(|resource| {
                resource.kind == "audio"
                    && resource.derivation_key.as_deref() == Some(PODCAST_AUDIO_DERIVATION_KEY)
            })
        })
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
    format!("<![CDATA[{}]]>", value.replace("]]>", "]]><![CDATA[>"))
}

fn setting_text(settings: &[Setting], key: &str) -> Option<String> {
    settings.iter().find_map(|setting| {
        (setting.key == key).then(|| match &setting.value {
            OptionValue::Text(value) => Some(value.clone()),
            OptionValue::Boolean(_) => None,
            OptionValue::Number(_) => None,
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
                OptionValue::Number(_) => None,
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
