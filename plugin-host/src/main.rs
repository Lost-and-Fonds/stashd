use std::fs;
use std::io::{BufRead, BufReader, Read, Write};
use std::os::unix::net::{UnixListener, UnixStream};
use std::path::{Path, PathBuf};
use std::process::Command;

use anyhow::{Context, Result};
use serde::{Deserialize, Serialize};
use wasmtime::component::{Component, HasSelf, Linker, Resource, ResourceTable};
use wasmtime::{Config, Engine, Store};
use wasmtime_wasi::{WasiCtx, WasiCtxBuilder, WasiCtxView, WasiView};

wasmtime::component::bindgen!({
    path: "../plugin-api/wit",
    world: "plugin-world",
    with: {
        "stashd:plugin/host/vault-asset": VaultAssetResource,
        "stashd:plugin/host/staging-output": StagingOutputResource,
    },
});

use exports::stashd::plugin::plugin::RunResult;
use stashd::plugin::host::Operation;
use stashd::plugin::host::{self, Host, HostStagingOutput, HostVaultAsset};

pub struct HttpClientResource;
pub struct StagingAreaResource;

mod youtube_input_world {
    wasmtime::component::bindgen!({
        path: "../plugin-api/wit",
        world: "youtube-input-world",
        with: {
            "stashd:plugin/input-host/http-client": super::HttpClientResource,
            "stashd:plugin/input-host/staging-area": super::StagingAreaResource,
        },
    });
}

use youtube_input_world::YoutubeInputWorld;
use youtube_input_world::exports::stashd::plugin::input_plugin::{
    AcquisitionOptions, AcquisitionResult, DiscoveredItem, DiscoveryMode, MediaKind, ResolvedInput,
};
use youtube_input_world::stashd::plugin::input_host::{
    self as input_host, ArtifactRole, HelperError, HelperResult, Host as InputHost, HostHttpClient,
    HostStagingArea, StagedArtifact, StagingError,
};

pub struct VaultAssetResource {
    bytes: Vec<u8>,
}

pub struct StagingOutputResource {
    path: PathBuf,
    bytes: Vec<u8>,
    finished: bool,
}

struct HostState {
    table: ResourceTable,
    wasi: WasiCtx,
    progress: Vec<host::Progress>,
    logs: Vec<String>,
}

struct InputState {
    table: ResourceTable,
    wasi: WasiCtx,
    progress: Vec<input_host::Progress>,
    logs: Vec<String>,
    fixture_dir: Option<PathBuf>,
    credential: Option<CredentialGrant>,
    staging_dir: Option<PathBuf>,
    helper: Option<HelperGrant>,
}

struct CredentialGrant {
    name: String,
    value: String,
}

struct HelperGrant {
    name: String,
    executable: PathBuf,
}

impl WasiView for HostState {
    fn ctx(&mut self) -> WasiCtxView<'_> {
        WasiCtxView {
            ctx: &mut self.wasi,
            table: &mut self.table,
        }
    }
}

impl WasiView for InputState {
    fn ctx(&mut self) -> WasiCtxView<'_> {
        WasiCtxView {
            ctx: &mut self.wasi,
            table: &mut self.table,
        }
    }
}

#[derive(Debug, Deserialize)]
struct Request {
    id: String,
    op: String,
    component_path: Option<PathBuf>,
    asset_path: Option<PathBuf>,
    staging_path: Option<PathBuf>,
    operation: Option<String>,
    source_uri: Option<String>,
    channel_id: Option<String>,
    fixture_dir: Option<PathBuf>,
    mode: Option<String>,
    credential_name: Option<String>,
    credential_value: Option<String>,
    staging_dir: Option<PathBuf>,
    helper_name: Option<String>,
    helper_executable: Option<PathBuf>,
    item: Option<AcquireItemRequest>,
    media_kind: Option<String>,
    include_captions: Option<bool>,
    caption_languages: Option<String>,
}

#[derive(Debug, Deserialize)]
struct AcquireItemRequest {
    provider_item_id: String,
    canonical_uri: String,
    title: String,
    description: Option<String>,
    published_at: Option<String>,
    thumbnail_uri: Option<String>,
    duration_seconds: Option<u32>,
    content_type: Option<String>,
}

#[derive(Debug, Serialize)]
#[serde(tag = "event")]
enum Response {
    #[serde(rename = "progress")]
    Progress {
        id: String,
        fraction: f32,
        stage: String,
    },
    #[serde(rename = "log")]
    Log { id: String, message: String },
    #[serde(rename = "result")]
    Result {
        id: String,
        source_bytes: u64,
        output_id: String,
        output_bytes: u64,
    },
    #[serde(rename = "input_resolved")]
    InputResolved {
        id: String,
        resolved: serde_json::Value,
    },
    #[serde(rename = "input_discovered")]
    InputDiscovered {
        id: String,
        items: serde_json::Value,
    },
    #[serde(rename = "input_acquired")]
    InputAcquired {
        id: String,
        acquisition: serde_json::Value,
    },
    #[serde(rename = "error")]
    Error {
        id: String,
        code: String,
        message: String,
    },
}

impl Host for HostState {
    fn report_progress(&mut self, progress: host::Progress) {
        self.progress.push(progress);
    }

    fn log(&mut self, message: String) {
        self.logs.push(message);
    }
}

impl HostVaultAsset for HostState {
    fn size(&mut self, asset: Resource<VaultAssetResource>) -> u64 {
        self.table
            .get(&asset)
            .map(|asset| asset.bytes.len() as u64)
            .unwrap_or_default()
    }

    fn read(
        &mut self,
        asset: Resource<VaultAssetResource>,
        offset: u64,
        maximum: u32,
    ) -> Result<Vec<u8>, host::AssetError> {
        let asset = self
            .table
            .get(&asset)
            .map_err(|_| host::AssetError::ReadFailed("asset handle expired".to_owned()))?;
        let end = offset
            .checked_add(u64::from(maximum))
            .filter(|end| *end <= asset.bytes.len() as u64)
            .ok_or(host::AssetError::OutOfBounds)?;

        Ok(asset.bytes[offset as usize..end as usize].to_vec())
    }

    fn drop(&mut self, asset: Resource<VaultAssetResource>) -> wasmtime::Result<()> {
        Ok(self.table.delete(asset).map(|_| ())?)
    }
}

impl HostStagingOutput for HostState {
    fn write(
        &mut self,
        output: Resource<StagingOutputResource>,
        bytes: Vec<u8>,
    ) -> Result<(), host::StagingError> {
        let output = self
            .table
            .get_mut(&output)
            .map_err(|_| host::StagingError::WriteFailed("output handle expired".to_owned()))?;
        if output.finished {
            return Err(host::StagingError::AlreadyFinished);
        }
        output.bytes.extend(bytes);
        Ok(())
    }

    fn finish(
        &mut self,
        output: Resource<StagingOutputResource>,
    ) -> Result<host::StagedOutput, host::StagingError> {
        let output = self
            .table
            .get_mut(&output)
            .map_err(|_| host::StagingError::WriteFailed("output handle expired".to_owned()))?;
        if output.finished {
            return Err(host::StagingError::AlreadyFinished);
        }
        output.finished = true;
        fs::write(&output.path, &output.bytes)
            .map_err(|error| host::StagingError::WriteFailed(error.to_string()))?;

        Ok(host::StagedOutput {
            id: "staging-output-0".to_owned(),
            bytes: output.bytes.len() as u64,
        })
    }

    fn drop(&mut self, output: Resource<StagingOutputResource>) -> wasmtime::Result<()> {
        Ok(self.table.delete(output).map(|_| ())?)
    }
}

impl InputHost for InputState {
    fn report_progress(&mut self, progress: input_host::Progress) {
        self.progress.push(progress);
    }

    fn log(&mut self, message: String) {
        self.logs.push(message);
    }
}

impl HostHttpClient for InputState {
    fn get(
        &mut self,
        client: Resource<HttpClientResource>,
        request: input_host::HttpRequest,
    ) -> Result<input_host::HttpResponse, input_host::HttpError> {
        let _ = self
            .table
            .get(&client)
            .map_err(|_| input_host::HttpError::Failed("HTTP capability expired".to_owned()))?;
        let url = authenticated_url(
            &request.url,
            request.credential.as_deref(),
            self.credential.as_ref(),
        )?;

        if let Some(directory) = &self.fixture_dir {
            let map_path = directory.join("map.json");
            let map: std::collections::HashMap<String, String> = serde_json::from_slice(
                &fs::read(&map_path)
                    .map_err(|error| input_host::HttpError::Failed(error.to_string()))?,
            )
            .map_err(|error| input_host::HttpError::Failed(error.to_string()))?;
            let fixture_url = fixture_lookup_url(&url, self.credential.as_ref());
            let filename = map
                .get(&fixture_url)
                .or_else(|| {
                    map.iter()
                        .find(|(pattern, _)| fixture_url.starts_with(pattern.as_str()))
                        .map(|(_, filename)| filename)
                })
                .ok_or_else(|| {
                    input_host::HttpError::Unavailable("fixture not found".to_owned())
                })?;
            let path = directory.join(filename);
            let body =
                fs::read(path).map_err(|error| input_host::HttpError::Failed(error.to_string()))?;
            let status = fixture_status(filename);
            return Ok(input_host::HttpResponse { status, body });
        }

        let agent = ureq::AgentBuilder::new().redirects(0).build();
        let response = agent.get(&url).call().map_err(|_| {
            input_host::HttpError::Unavailable("approved HTTP request unavailable".to_owned())
        })?;
        let status = response.status() as u16;
        let mut body = Vec::new();
        response.into_reader().read_to_end(&mut body).map_err(|_| {
            input_host::HttpError::Failed("approved HTTP response could not be read".to_owned())
        })?;
        Ok(input_host::HttpResponse { status, body })
    }

    fn drop(&mut self, client: Resource<HttpClientResource>) -> wasmtime::Result<()> {
        Ok(self.table.delete(client).map(|_| ())?)
    }
}

impl HostStagingArea for InputState {
    fn run_helper(
        &mut self,
        area: Resource<StagingAreaResource>,
        name: String,
        args: Vec<String>,
    ) -> Result<HelperResult, HelperError> {
        let _ = self
            .table
            .get(&area)
            .map_err(|_| HelperError::Failed("staging capability expired".to_owned()))?;
        let staging = self
            .staging_dir
            .as_ref()
            .ok_or_else(|| HelperError::Unavailable("staging workspace unavailable".to_owned()))?;
        let helper = self.helper.as_ref().ok_or(HelperError::Denied)?;
        if helper.name != name {
            return Err(HelperError::Denied);
        }
        let before = workspace_files(staging).map_err(HelperError::Failed)?;
        let executable = if helper.executable.is_absolute() {
            helper.executable.clone()
        } else {
            std::env::current_dir()
                .map_err(|error| HelperError::Unavailable(error.to_string()))?
                .join(&helper.executable)
        };
        let output = Command::new(executable)
            .args(args)
            .current_dir(staging)
            .output()
            .map_err(|error| HelperError::Unavailable(error.to_string()))?;
        let after = workspace_files(staging).map_err(HelperError::Failed)?;
        let files = after.difference(&before).cloned().collect();
        Ok(HelperResult {
            exit_code: output.status.code().unwrap_or(-1),
            stdout: String::from_utf8_lossy(&output.stdout).into_owned(),
            stderr: String::from_utf8_lossy(&output.stderr).into_owned(),
            files,
        })
    }

    fn stage(
        &mut self,
        area: Resource<StagingAreaResource>,
        relative_path: String,
        role: ArtifactRole,
        media_type: Option<String>,
    ) -> Result<StagedArtifact, StagingError> {
        let _ = self
            .table
            .get(&area)
            .map_err(|_| StagingError::Failed("staging capability expired".to_owned()))?;
        let staging = self.staging_dir.as_ref().ok_or(StagingError::Missing)?;
        let path =
            safe_staging_path(staging, &relative_path).ok_or(StagingError::InvalidReference)?;
        let metadata = fs::metadata(&path).map_err(|_| StagingError::Missing)?;
        if !metadata.is_file() {
            return Err(StagingError::Missing);
        }
        Ok(StagedArtifact {
            reference: relative_path,
            role,
            media_type,
            size_bytes: metadata.len(),
        })
    }

    fn drop(&mut self, area: Resource<StagingAreaResource>) -> wasmtime::Result<()> {
        Ok(self.table.delete(area).map(|_| ())?)
    }
}

fn safe_staging_path(root: &Path, relative: &str) -> Option<PathBuf> {
    let path = Path::new(relative);
    if relative.is_empty()
        || path.is_absolute()
        || path
            .components()
            .any(|component| matches!(component, std::path::Component::ParentDir))
    {
        return None;
    }
    Some(root.join(path))
}

fn workspace_files(root: &Path) -> Result<std::collections::BTreeSet<String>, String> {
    let mut files = std::collections::BTreeSet::new();
    for entry in fs::read_dir(root).map_err(|error| error.to_string())? {
        let entry = entry.map_err(|error| error.to_string())?;
        if entry
            .file_type()
            .map_err(|error| error.to_string())?
            .is_file()
        {
            files.insert(entry.file_name().to_string_lossy().into_owned());
        }
    }
    Ok(files)
}

fn fixture_status(filename: &str) -> u16 {
    match filename {
        "unavailable.txt" => 404,
        "auth_rejected.json" => 401,
        "rate_limited.json" => 429,
        _ => 200,
    }
}

fn fixture_lookup_url(url: &str, grant: Option<&CredentialGrant>) -> String {
    grant.map_or_else(
        || url.to_owned(),
        |grant| url.replace(&format!("&key={}", grant.value), "&key=test-api-key"),
    )
}

fn authenticated_url(
    url: &str,
    requested_credential: Option<&str>,
    grant: Option<&CredentialGrant>,
) -> Result<String, input_host::HttpError> {
    if allowed_youtube_url(url) {
        if requested_credential.is_some() {
            return Err(input_host::HttpError::Denied);
        }
        return Ok(url.to_owned());
    }

    if !allowed_data_api_url(url) {
        return Err(input_host::HttpError::Denied);
    }

    let requested = requested_credential.ok_or(input_host::HttpError::CredentialUnavailable)?;
    let grant = grant.ok_or(input_host::HttpError::CredentialUnavailable)?;
    if requested != grant.name || grant.name != "youtube-data-api" || grant.value.is_empty() {
        return Err(input_host::HttpError::CredentialUnavailable);
    }

    let mut parsed = url::Url::parse(url)
        .map_err(|_| input_host::HttpError::Failed("invalid approved API URL".to_owned()))?;
    parsed.query_pairs_mut().append_pair("key", &grant.value);
    Ok(parsed.to_string())
}

fn allowed_youtube_url(url: &str) -> bool {
    let Some(path) = url.strip_prefix("https://www.youtube.com") else {
        return false;
    };
    path.starts_with("/@")
        || path.starts_with("/c/")
        || path.starts_with("/user/")
        || path.starts_with("/channel/")
        || path.starts_with("/feeds/videos.xml?channel_id=")
}

fn allowed_data_api_url(url: &str) -> bool {
    let Some(path) = url.strip_prefix("https://www.googleapis.com/youtube/v3/") else {
        return false;
    };
    path.starts_with("channels?")
        || path.starts_with("playlistItems?")
        || path.starts_with("videos?")
}

#[allow(clippy::too_many_arguments)]
fn invoke_input(
    engine: &Engine,
    component: &Component,
    fixture_dir: Option<PathBuf>,
    source_uri: Option<&str>,
    channel_id: Option<&str>,
    mode: Option<&str>,
    credential_name: Option<String>,
    credential_value: Option<String>,
) -> Result<(
    Option<ResolvedInput>,
    Option<Vec<DiscoveredItem>>,
    InputState,
)> {
    let mut store = Store::new(
        engine,
        InputState {
            table: ResourceTable::new(),
            wasi: WasiCtxBuilder::new().build(),
            progress: Vec::new(),
            logs: Vec::new(),
            fixture_dir,
            credential: credential_name
                .zip(credential_value)
                .map(|(name, value)| CredentialGrant { name, value }),
            staging_dir: None,
            helper: None,
        },
    );
    let mut linker = Linker::new(engine);
    wasmtime_wasi::p2::add_to_linker_sync(&mut linker)?;
    YoutubeInputWorld::add_to_linker::<_, HasSelf<_>>(&mut linker, |state| state)?;
    let plugin = YoutubeInputWorld::instantiate(&mut store, component, &linker)?;
    let client = store.data_mut().table.push(HttpClientResource)?;
    let (resolved, items) = if let Some(source) = source_uri {
        (
            Some(
                plugin
                    .stashd_plugin_input_plugin()
                    .call_resolve(&mut store, client, source)?
                    .map_err(|error| anyhow::anyhow!("plugin input error: {error:?}"))?,
            ),
            None,
        )
    } else {
        let channel = channel_id.context("input discovery requires channel_id")?;
        let mode = match mode.unwrap_or("rss") {
            "rss" => DiscoveryMode::Rss,
            "data-api" => DiscoveryMode::DataApi,
            other => anyhow::bail!("unsupported discovery mode: {other}"),
        };
        (
            None,
            Some(
                plugin
                    .stashd_plugin_input_plugin()
                    .call_discover(&mut store, client, channel, mode)?
                    .map_err(|error| anyhow::anyhow!("plugin input error: {error:?}"))?,
            ),
        )
    };
    Ok((resolved, items, store.into_data()))
}

#[allow(clippy::too_many_arguments)]
fn invoke_acquire(
    engine: &Engine,
    component: &Component,
    staging_dir: Option<PathBuf>,
    helper_name: Option<String>,
    helper_executable: Option<PathBuf>,
    item: AcquireItemRequest,
    media_kind: &str,
    include_captions: bool,
    caption_languages: Option<String>,
) -> Result<(AcquisitionResult, InputState)> {
    let mut store = Store::new(
        engine,
        InputState {
            table: ResourceTable::new(),
            wasi: WasiCtxBuilder::new().build(),
            progress: Vec::new(),
            logs: Vec::new(),
            fixture_dir: None,
            credential: None,
            staging_dir,
            helper: helper_name
                .zip(helper_executable)
                .map(|(name, executable)| HelperGrant { name, executable }),
        },
    );
    let mut linker = Linker::new(engine);
    wasmtime_wasi::p2::add_to_linker_sync(&mut linker)?;
    YoutubeInputWorld::add_to_linker::<_, HasSelf<_>>(&mut linker, |state| state)?;
    let plugin = YoutubeInputWorld::instantiate(&mut store, component, &linker)?;
    let staging = store.data_mut().table.push(StagingAreaResource)?;
    let item = DiscoveredItem {
        provider_item_id: item.provider_item_id,
        canonical_uri: item.canonical_uri,
        title: item.title,
        description: item.description,
        published_at: item.published_at,
        thumbnail_uri: item.thumbnail_uri,
        duration_seconds: item.duration_seconds,
        content_type: item.content_type,
    };
    let media_kind = match media_kind {
        "video" => MediaKind::Video,
        "audio" => MediaKind::Audio,
        other => anyhow::bail!("unsupported media kind: {other}"),
    };
    let result = plugin
        .stashd_plugin_input_plugin()
        .call_acquire(
            &mut store,
            staging,
            &item,
            &AcquisitionOptions {
                media_kind,
                include_captions,
                caption_languages,
            },
        )?
        .map_err(|error| anyhow::anyhow!("plugin input error: {error:?}"))?;
    Ok((result, store.into_data()))
}

fn resolved_json(value: &ResolvedInput) -> serde_json::Value {
    serde_json::json!({
        "provider_key": value.provider_key,
        "input_type": value.input_type,
        "source_uri": value.source_uri,
        "canonical_source_uri": value.canonical_source_uri,
        "provider_input_id": value.provider_input_id,
        "title": value.title,
        "avatar_uri": value.avatar_uri,
        "estimated_item_count": value.estimated_item_count,
    })
}

fn item_json(value: &DiscoveredItem) -> serde_json::Value {
    serde_json::json!({
        "provider_item_id": value.provider_item_id,
        "canonical_uri": value.canonical_uri,
        "title": value.title,
        "description": value.description,
        "published_at": value.published_at,
        "thumbnail_uri": value.thumbnail_uri,
        "duration_seconds": value.duration_seconds,
        "content_type": value.content_type,
    })
}

fn artifact_json(value: &StagedArtifact) -> serde_json::Value {
    let role = match value.role {
        ArtifactRole::Primary => "primary",
        ArtifactRole::Captions => "captions",
        ArtifactRole::Thumbnail => "thumbnail",
        ArtifactRole::ProviderMetadata => "provider-metadata",
    };
    serde_json::json!({
        "reference": value.reference,
        "role": role,
        "media_type": value.media_type,
        "size_bytes": value.size_bytes,
    })
}

fn plugin_error_code(message: &str) -> &'static str {
    [
        ("UnsupportedSource", "unsupported_source"),
        ("ChannelResolutionFailed", "channel_resolution_failed"),
        ("UpstreamUnavailable", "upstream_unavailable"),
        ("MalformedFeed", "malformed_feed"),
        ("SourceNotFound", "source_not_found"),
        ("MalformedApiResponse", "malformed_api_response"),
        ("CredentialUnavailable", "credential_unavailable"),
        ("AuthenticationRejected", "authentication_rejected"),
        ("RateLimited", "rate_limited"),
        ("HelperUnavailable", "helper_unavailable"),
        ("HelperFailed", "helper_failed"),
        ("AcquisitionTimeout", "acquisition_timeout"),
        ("UnsupportedMedia", "unsupported_media"),
        (
            "UnexpectedAcquisitionResult",
            "unexpected_acquisition_result",
        ),
    ]
    .into_iter()
    .find_map(|(variant, code)| message.contains(variant).then_some(code))
    .unwrap_or("plugin_error")
}

fn invoke(
    engine: &Engine,
    component: Component,
    asset_path: &Path,
    staging_path: &Path,
    operation: Operation,
) -> Result<(RunResult, HostState)> {
    let bytes = fs::read(asset_path)
        .with_context(|| format!("reading granted asset {}", asset_path.display()))?;
    let mut store = Store::new(
        engine,
        HostState {
            table: ResourceTable::new(),
            wasi: WasiCtxBuilder::new().build(),
            progress: Vec::new(),
            logs: Vec::new(),
        },
    );
    let mut linker = Linker::new(engine);
    wasmtime_wasi::p2::add_to_linker_sync(&mut linker)?;
    PluginWorld::add_to_linker::<_, HasSelf<_>>(&mut linker, |state| state)?;
    let plugin = PluginWorld::instantiate(&mut store, &component, &linker)?;
    let asset = store.data_mut().table.push(VaultAssetResource { bytes })?;
    let staging = store.data_mut().table.push(StagingOutputResource {
        path: staging_path.to_owned(),
        bytes: Vec::new(),
        finished: false,
    })?;
    let result = plugin
        .interface0
        .call_run(&mut store, asset, staging, operation)?;
    let state = store.into_data();

    result
        .map(|result| (result, state))
        .map_err(|error| anyhow::anyhow!("plugin rejected invocation: {error:?}"))
}

fn operation(value: Option<&str>) -> Result<Operation> {
    match value.unwrap_or("copy") {
        "copy" => Ok(Operation::Copy),
        "typed-failure" => Ok(Operation::TypedFailure),
        "trap" => Ok(Operation::Trap),
        other => anyhow::bail!("unsupported example operation: {other}"),
    }
}

fn send(stream: &mut UnixStream, response: Response) -> Result<()> {
    serde_json::to_writer(&mut *stream, &response)?;
    stream.write_all(b"\n")?;
    stream.flush()?;
    Ok(())
}

fn handle_request(engine: &Engine, stream: &mut UnixStream, request: Request) -> Result<()> {
    match request.op.as_str() {
        "inspect" => {
            let component_path = request
                .component_path
                .as_deref()
                .context("inspect requires component_path")?;
            Component::from_file(engine, component_path)
                .with_context(|| format!("loading component {}", component_path.display()))?;
            send(
                stream,
                Response::Result {
                    id: request.id,
                    source_bytes: 0,
                    output_id: "component-valid".to_owned(),
                    output_bytes: 0,
                },
            )?;
        }
        "invoke" => {
            let component_path = request
                .component_path
                .as_deref()
                .context("invoke requires component_path")?;
            let asset_path = request
                .asset_path
                .as_deref()
                .context("invoke requires asset_path")?;
            let staging_path = request
                .staging_path
                .as_deref()
                .context("invoke requires staging_path")?;
            let component = Component::from_file(engine, component_path)
                .with_context(|| format!("loading component {}", component_path.display()))?;
            let result = invoke(
                engine,
                component,
                asset_path,
                staging_path,
                operation(request.operation.as_deref())?,
            );
            match result {
                Ok((result, state)) => {
                    for progress in state.progress {
                        send(
                            stream,
                            Response::Progress {
                                id: request.id.clone(),
                                fraction: progress.fraction,
                                stage: progress.stage,
                            },
                        )?;
                    }
                    for message in state.logs {
                        send(
                            stream,
                            Response::Log {
                                id: request.id.clone(),
                                message,
                            },
                        )?;
                    }
                    send(
                        stream,
                        Response::Result {
                            id: request.id,
                            source_bytes: result.source_bytes,
                            output_id: result.output_id,
                            output_bytes: result.output_bytes,
                        },
                    )?;
                }
                Err(error) => send(
                    stream,
                    Response::Error {
                        id: request.id,
                        code: "execution_error".to_owned(),
                        message: error.to_string(),
                    },
                )?,
            }
        }
        "input-acquire" => {
            let component_path = request
                .component_path
                .as_deref()
                .context("input acquisition requires component_path")?;
            let component = Component::from_file(engine, component_path)
                .with_context(|| format!("loading component {}", component_path.display()))?;
            let (result, state) = match invoke_acquire(
                engine,
                &component,
                request.staging_dir,
                request.helper_name,
                request.helper_executable,
                request.item.context("input acquisition requires item")?,
                request.media_kind.as_deref().unwrap_or("video"),
                request.include_captions.unwrap_or(false),
                request.caption_languages,
            ) {
                Ok(result) => result,
                Err(error) => {
                    let message = error.to_string();
                    send(
                        stream,
                        Response::Error {
                            id: request.id,
                            code: plugin_error_code(&message).to_owned(),
                            message,
                        },
                    )?;
                    return Ok(());
                }
            };
            for progress in state.progress {
                send(
                    stream,
                    Response::Progress {
                        id: request.id.clone(),
                        fraction: 0.0,
                        stage: progress.stage,
                    },
                )?;
            }
            for message in state.logs {
                send(
                    stream,
                    Response::Log {
                        id: request.id.clone(),
                        message,
                    },
                )?;
            }
            send(
                stream,
                Response::InputAcquired {
                    id: request.id,
                    acquisition: serde_json::json!({
                        "artifacts": result.artifacts.iter().map(artifact_json).collect::<Vec<_>>(),
                    }),
                },
            )?;
        }
        "input-resolve" | "input-discover" => {
            let component_path = request
                .component_path
                .as_deref()
                .context("input invocation requires component_path")?;
            let component = Component::from_file(engine, component_path)
                .with_context(|| format!("loading component {}", component_path.display()))?;
            let result = invoke_input(
                engine,
                &component,
                request.fixture_dir,
                (request.op == "input-resolve")
                    .then_some(request.source_uri.as_deref())
                    .flatten(),
                (request.op == "input-discover")
                    .then_some(request.channel_id.as_deref())
                    .flatten(),
                request.mode.as_deref(),
                request.credential_name,
                request.credential_value,
            );
            let (resolved, items, state) = match result {
                Ok(result) => result,
                Err(error) => {
                    let message = error.to_string();
                    send(
                        stream,
                        Response::Error {
                            id: request.id,
                            code: plugin_error_code(&message).to_owned(),
                            message,
                        },
                    )?;
                    return Ok(());
                }
            };
            for progress in state.progress {
                send(
                    stream,
                    Response::Progress {
                        id: request.id.clone(),
                        fraction: 0.0,
                        stage: progress.stage,
                    },
                )?;
            }
            for message in state.logs {
                send(
                    stream,
                    Response::Log {
                        id: request.id.clone(),
                        message,
                    },
                )?;
            }
            if let Some(value) = resolved {
                send(
                    stream,
                    Response::InputResolved {
                        id: request.id,
                        resolved: resolved_json(&value),
                    },
                )?;
            } else if let Some(values) = items {
                send(
                    stream,
                    Response::InputDiscovered {
                        id: request.id,
                        items: serde_json::Value::Array(values.iter().map(item_json).collect()),
                    },
                )?;
            }
        }
        "cancel" => send(
            stream,
            Response::Error {
                id: request.id,
                code: "not_running".to_owned(),
                message: "the synchronous spike host has no active cancellation target".to_owned(),
            },
        )?,
        other => send(
            stream,
            Response::Error {
                id: request.id,
                code: "unsupported_operation".to_owned(),
                message: format!("unsupported IPC operation: {other}"),
            },
        )?,
    }

    Ok(())
}

fn serve(engine: &Engine, socket_path: &Path) -> Result<()> {
    if socket_path.exists() {
        fs::remove_file(socket_path)?;
    }
    let listener = UnixListener::bind(socket_path)
        .with_context(|| format!("binding private Unix socket {}", socket_path.display()))?;
    eprintln!("stashd-plugin-host listening on {}", socket_path.display());

    for stream in listener.incoming() {
        let mut stream = stream?;
        let mut line = String::new();
        BufReader::new(stream.try_clone()?).read_line(&mut line)?;
        let request: Request = match serde_json::from_str(&line) {
            Ok(request) => request,
            Err(error) => {
                send(
                    &mut stream,
                    Response::Error {
                        id: "unknown".to_owned(),
                        code: "invalid_request".to_owned(),
                        message: error.to_string(),
                    },
                )?;
                continue;
            }
        };
        let request_id = request.id.clone();
        if let Err(error) = handle_request(engine, &mut stream, request) {
            eprintln!("plugin host request failed: {error:#}");
            send(
                &mut stream,
                Response::Error {
                    id: request_id,
                    code: "execution_error".to_owned(),
                    message: error.to_string(),
                },
            )?;
        }
    }

    Ok(())
}

fn write_component(engine: &Engine, core_path: &Path, output_path: &Path) -> Result<()> {
    let core = fs::read(core_path)?;
    if Component::new(engine, &core).is_ok() {
        fs::write(output_path, core)?;
        return Ok(());
    }
    let component = wit_component::ComponentEncoder::default()
        .module(&core)?
        .validate(true)
        .encode()
        .context("encoding core module as a WebAssembly Component")?;
    let _ = Component::new(engine, &component)?;
    fs::write(output_path, component)?;
    Ok(())
}

fn main() -> Result<()> {
    let mut config = Config::new();
    config.wasm_component_model(true);
    let engine = Engine::new(&config)?;
    let mut args = std::env::args().skip(1);
    match args.next().as_deref() {
        Some("build-component") => {
            let core = PathBuf::from(args.next().context("missing core module path")?);
            let output = PathBuf::from(args.next().context("missing component output path")?);
            write_component(&engine, &core, &output)
        }
        Some("serve") => {
            let socket = PathBuf::from(args.next().context("missing socket path")?);
            serve(&engine, &socket)
        }
        _ => anyhow::bail!(
            "usage: stashd-plugin-host build-component <core.wasm> <component.wasm> | serve <socket>"
        ),
    }
}

#[cfg(test)]
mod tests {
    use super::{CredentialGrant, allowed_youtube_url, authenticated_url, input_host};

    #[test]
    fn input_http_capability_allows_only_youtube_channel_requests() {
        assert!(allowed_youtube_url("https://www.youtube.com/@StashdDemo"));
        assert!(allowed_youtube_url(
            "https://www.youtube.com/feeds/videos.xml?channel_id=UCStashdDemoCh0012345678"
        ));
        assert!(!allowed_youtube_url(
            "https://www.youtube.com/watch?v=demoVideo01"
        ));
        assert!(!allowed_youtube_url("https://example.com/anything"));
        assert!(!allowed_youtube_url("http://www.youtube.com/@StashdDemo"));
    }

    #[test]
    fn credential_use_is_bound_to_the_approved_api_origin_and_grant() {
        let grant = CredentialGrant {
            name: "youtube-data-api".to_owned(),
            value: "fixture-secret-do-not-cross-wasm".to_owned(),
        };
        let url = authenticated_url(
            "https://www.googleapis.com/youtube/v3/videos?id=demoVideo01&part=snippet",
            Some("youtube-data-api"),
            Some(&grant),
        )
        .expect("approved credential use should succeed");
        assert!(url.contains("key=fixture-secret-do-not-cross-wasm"));
        assert!(matches!(
            authenticated_url(
                "https://www.googleapis.com/youtube/v3/videos?id=x",
                None,
                Some(&grant)
            ),
            Err(input_host::HttpError::CredentialUnavailable)
        ));
        assert!(matches!(
            authenticated_url(
                "https://example.com/redirect",
                Some("youtube-data-api"),
                Some(&grant)
            ),
            Err(input_host::HttpError::Denied)
        ));
        assert!(matches!(
            authenticated_url(
                "http://www.googleapis.com/youtube/v3/videos?id=x",
                Some("youtube-data-api"),
                Some(&grant)
            ),
            Err(input_host::HttpError::Denied)
        ));
        assert!(matches!(
            authenticated_url(
                "https://www.youtube.com/@StashdDemo",
                Some("youtube-data-api"),
                Some(&grant)
            ),
            Err(input_host::HttpError::Denied)
        ));
    }
}
