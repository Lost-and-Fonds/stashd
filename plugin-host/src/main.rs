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
    path: "../plugin-api/spike-wit",
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

mod input_world {
    wasmtime::component::bindgen!({
        path: "../plugin-api/wit",
        world: "input-world",
        with: {
            "stashd:plugin/input-host/http-client": super::HttpClientResource,
            "stashd:plugin/input-host/staging-area": super::StagingAreaResource,
        },
    });
}

mod broadcast_world {
    wasmtime::component::bindgen!({
        path: "../plugin-api/wit/broadcast.wit",
        world: "broadcast-world",
        with: {
            "stashd:plugin/broadcast-host/staging-area": super::BroadcastStagingAreaResource,
        },
    });
}

use input_world::InputWorld;
use input_world::exports::stashd::plugin::input_plugin::{
    AcquisitionOptions, AcquisitionResult, DiscoveredItem, DiscoveryIntent, MediaKind,
    ResolvedInput,
};
use input_world::stashd::plugin::input_host::{
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

pub struct BroadcastStagingAreaResource;

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
    http_grants: Vec<HttpGrant>,
    staging_dir: Option<PathBuf>,
    helper: Option<HelperGrant>,
}

struct BroadcastState {
    table: ResourceTable,
    wasi: WasiCtx,
    progress: Vec<String>,
    logs: Vec<String>,
    staging_dir: PathBuf,
}

struct CredentialGrant {
    name: String,
    value: String,
    query_parameter: String,
}

struct HttpGrant {
    allowed_prefixes: Vec<String>,
    credential: Option<CredentialGrant>,
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

impl WasiView for BroadcastState {
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
    source: Option<String>,
    input_id: Option<String>,
    fixture_dir: Option<PathBuf>,
    intent: Option<String>,
    http_grants: Option<Vec<HttpGrantRequest>>,
    staging_dir: Option<PathBuf>,
    helper_name: Option<String>,
    helper_executable: Option<PathBuf>,
    item: Option<AcquireItemRequest>,
    media_kind: Option<String>,
    options: Option<Vec<InputOptionRequest>>,
    broadcast: Option<BroadcastPublishRequest>,
}

#[derive(Debug, Deserialize)]
struct InputOptionRequest {
    key: String,
    value: InputOptionValueRequest,
}

#[derive(Debug, Deserialize)]
#[serde(tag = "kind", content = "value")]
enum InputOptionValueRequest {
    #[serde(rename = "boolean")]
    Boolean(bool),
    #[serde(rename = "text")]
    Text(String),
}

#[derive(Debug, Deserialize)]
struct HttpGrantRequest {
    allowed_prefixes: Vec<String>,
    credential_name: Option<String>,
    credential_value: Option<String>,
    credential_parameter: Option<String>,
}

#[derive(Debug, Deserialize)]
struct AcquireItemRequest {
    id: String,
    reference: String,
    title: String,
    description: Option<String>,
    published_at: Option<String>,
    artwork_reference: Option<String>,
    duration_seconds: Option<u32>,
    kind: Option<String>,
}

#[derive(Debug, Deserialize)]
struct BroadcastPublishRequest {
    reference: String,
    settings: Vec<InputOptionRequest>,
    items: Vec<BroadcastItemRequest>,
}

#[derive(Debug, Deserialize)]
struct BroadcastResourceRequest {
    reference: String,
    kind: String,
    url: Option<String>,
    media_type: Option<String>,
    size_bytes: u64,
}

#[derive(Debug, Deserialize)]
struct BroadcastItemRequest {
    id: String,
    title: String,
    description: Option<String>,
    published_at: Option<String>,
    duration_seconds: Option<u32>,
    resources: Vec<BroadcastResourceRequest>,
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
    #[serde(rename = "broadcast_published")]
    BroadcastPublished {
        id: String,
        publication: serde_json::Value,
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

impl broadcast_world::stashd::plugin::broadcast_host::Host for BroadcastState {
    fn open_staging_area(&mut self) -> Resource<BroadcastStagingAreaResource> {
        self.table
            .push(BroadcastStagingAreaResource)
            .expect("resource table is available")
    }

    fn report_progress(&mut self, stage: String) {
        self.progress.push(stage);
    }

    fn log(&mut self, message: String) {
        self.logs.push(message);
    }
}

impl broadcast_world::stashd::plugin::broadcast_host::HostStagingArea for BroadcastState {
    fn write(
        &mut self,
        area: Resource<BroadcastStagingAreaResource>,
        relative_path: String,
        content: Vec<u8>,
        media_type: Option<String>,
    ) -> Result<
        broadcast_world::stashd::plugin::broadcast_host::StagedArtifact,
        broadcast_world::stashd::plugin::broadcast_host::StagingError,
    > {
        let _ = self.table.get(&area).map_err(|_| {
            broadcast_world::stashd::plugin::broadcast_host::StagingError::Failed(
                "staging capability expired".to_owned(),
            )
        })?;
        let path = safe_staging_path(&self.staging_dir, &relative_path).ok_or(
            broadcast_world::stashd::plugin::broadcast_host::StagingError::InvalidReference,
        )?;
        if let Some(parent) = path.parent() {
            fs::create_dir_all(parent).map_err(|error| {
                broadcast_world::stashd::plugin::broadcast_host::StagingError::Failed(
                    error.to_string(),
                )
            })?;
        }
        fs::write(&path, &content).map_err(|error| {
            broadcast_world::stashd::plugin::broadcast_host::StagingError::Failed(error.to_string())
        })?;
        Ok(
            broadcast_world::stashd::plugin::broadcast_host::StagedArtifact {
                reference: relative_path,
                media_type,
                size_bytes: content.len() as u64,
            },
        )
    }

    fn drop(&mut self, area: Resource<BroadcastStagingAreaResource>) -> wasmtime::Result<()> {
        Ok(self.table.delete(area).map(|_| ())?)
    }
}

impl InputHost for InputState {
    fn open_http_client(&mut self) -> Resource<HttpClientResource> {
        self.table
            .push(HttpClientResource)
            .expect("resource table is available")
    }

    fn open_staging_area(&mut self) -> Resource<StagingAreaResource> {
        self.table
            .push(StagingAreaResource)
            .expect("resource table is available")
    }

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
            &self.http_grants,
        )?;

        if let Some(directory) = &self.fixture_dir {
            let map_path = directory.join("map.json");
            let map: std::collections::HashMap<String, String> = serde_json::from_slice(
                &fs::read(&map_path)
                    .map_err(|error| input_host::HttpError::Failed(error.to_string()))?,
            )
            .map_err(|error| input_host::HttpError::Failed(error.to_string()))?;
            let fixture_url = fixture_lookup_url(&url, &self.http_grants);
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

fn fixture_lookup_url(url: &str, grants: &[HttpGrant]) -> String {
    grants.iter().fold(url.to_owned(), |url, grant| {
        grant.credential.as_ref().map_or(url.clone(), |credential| {
            url.replace(
                &format!("&{}={}", credential.query_parameter, credential.value),
                &format!("&{}=test-api-key", credential.query_parameter),
            )
        })
    })
}

fn authenticated_url(
    url: &str,
    requested_credential: Option<&str>,
    grants: &[HttpGrant],
) -> Result<String, input_host::HttpError> {
    if !url.starts_with("https://") {
        return Err(input_host::HttpError::Denied);
    }
    let prefix_allowed = grants.iter().any(|grant| {
        grant
            .allowed_prefixes
            .iter()
            .any(|prefix| url.starts_with(prefix))
    });
    if !prefix_allowed {
        return Err(input_host::HttpError::Denied);
    }

    if let Some(requested) = requested_credential {
        let grant = grants.iter().find_map(|grant| {
            let credential = grant.credential.as_ref()?;
            (grant
                .allowed_prefixes
                .iter()
                .any(|prefix| url.starts_with(prefix))
                && credential.name == requested
                && !credential.value.is_empty()
                && !credential.query_parameter.is_empty())
            .then_some(credential)
        });
        let grant = grant.ok_or(input_host::HttpError::CredentialUnavailable)?;
        let mut parsed = url::Url::parse(url)
            .map_err(|_| input_host::HttpError::Failed("invalid approved URL".to_owned()))?;
        parsed
            .query_pairs_mut()
            .append_pair(&grant.query_parameter, &grant.value);
        return Ok(parsed.to_string());
    }

    if grants.iter().any(|grant| grant.credential.is_none()) {
        return Ok(url.to_owned());
    }
    Err(input_host::HttpError::CredentialUnavailable)
}

fn into_http_grants(requests: Option<Vec<HttpGrantRequest>>) -> Vec<HttpGrant> {
    requests
        .unwrap_or_default()
        .into_iter()
        .map(|request| HttpGrant {
            allowed_prefixes: request.allowed_prefixes,
            credential: request
                .credential_name
                .zip(request.credential_value)
                .zip(request.credential_parameter)
                .map(|((name, value), query_parameter)| CredentialGrant {
                    name,
                    value,
                    query_parameter,
                }),
        })
        .collect()
}

#[allow(clippy::too_many_arguments)]
fn invoke_input(
    engine: &Engine,
    component: &Component,
    fixture_dir: Option<PathBuf>,
    source: Option<&str>,
    input_id: Option<&str>,
    intent: Option<&str>,
    http_grant_requests: Option<Vec<HttpGrantRequest>>,
    options: Option<Vec<InputOptionRequest>>,
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
            http_grants: into_http_grants(http_grant_requests),
            staging_dir: None,
            helper: None,
        },
    );
    let mut linker = Linker::new(engine);
    wasmtime_wasi::p2::add_to_linker_sync(&mut linker)?;
    InputWorld::add_to_linker::<_, HasSelf<_>>(&mut linker, |state| state)?;
    let plugin = InputWorld::instantiate(&mut store, component, &linker)?;
    let (resolved, items) = if let Some(source) = source {
        (
            Some(
                plugin
                    .stashd_plugin_input_plugin()
                    .call_resolve(&mut store, source)?
                    .map_err(|error| anyhow::anyhow!("plugin input error: {error:?}"))?,
            ),
            None,
        )
    } else {
        let input_id = input_id.context("input discovery requires input_id")?;
        let intent = match intent.unwrap_or("refresh") {
            "refresh" => DiscoveryIntent::Refresh,
            "complete" => DiscoveryIntent::Complete,
            other => anyhow::bail!("unsupported discovery intent: {other}"),
        };
        let options = into_input_options(options.unwrap_or_default());
        (
            None,
            Some(
                plugin
                    .stashd_plugin_input_plugin()
                    .call_discover(&mut store, input_id, intent, &options)?
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
    options: Vec<InputOptionRequest>,
) -> Result<(AcquisitionResult, InputState)> {
    let mut store = Store::new(
        engine,
        InputState {
            table: ResourceTable::new(),
            wasi: WasiCtxBuilder::new().build(),
            progress: Vec::new(),
            logs: Vec::new(),
            fixture_dir: None,
            http_grants: Vec::new(),
            staging_dir,
            helper: helper_name
                .zip(helper_executable)
                .map(|(name, executable)| HelperGrant { name, executable }),
        },
    );
    let mut linker = Linker::new(engine);
    wasmtime_wasi::p2::add_to_linker_sync(&mut linker)?;
    InputWorld::add_to_linker::<_, HasSelf<_>>(&mut linker, |state| state)?;
    let plugin = InputWorld::instantiate(&mut store, component, &linker)?;
    let item = DiscoveredItem {
        id: item.id,
        reference: item.reference,
        title: item.title,
        description: item.description,
        published_at: item.published_at,
        artwork_reference: item.artwork_reference,
        duration_seconds: item.duration_seconds,
        kind: item.kind,
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
            &item,
            &AcquisitionOptions {
                media_kind,
                options: into_input_options(options),
            },
        )?
        .map_err(|error| anyhow::anyhow!("plugin input error: {error:?}"))?;
    Ok((result, store.into_data()))
}

fn into_input_options(
    requests: Vec<InputOptionRequest>,
) -> Vec<input_world::exports::stashd::plugin::input_plugin::InputOption> {
    requests
        .into_iter()
        .map(
            |request| input_world::exports::stashd::plugin::input_plugin::InputOption {
                key: request.key,
                value: match request.value {
                    InputOptionValueRequest::Boolean(value) => {
                        input_world::exports::stashd::plugin::input_plugin::OptionValue::Boolean(
                            value,
                        )
                    }
                    InputOptionValueRequest::Text(value) => {
                        input_world::exports::stashd::plugin::input_plugin::OptionValue::Text(value)
                    }
                },
            },
        )
        .collect()
}

fn resolved_json(value: &ResolvedInput) -> serde_json::Value {
    serde_json::json!({
        "id": value.id,
        "canonical_reference": value.canonical_reference,
        "kind": value.kind,
        "title": value.title,
        "artwork_reference": value.artwork_reference,
        "estimated_item_count": value.estimated_item_count,
    })
}

fn item_json(value: &DiscoveredItem) -> serde_json::Value {
    serde_json::json!({
        "id": value.id,
        "reference": value.reference,
        "title": value.title,
        "description": value.description,
        "published_at": value.published_at,
        "artwork_reference": value.artwork_reference,
        "duration_seconds": value.duration_seconds,
        "kind": value.kind,
    })
}

fn artifact_json(value: &StagedArtifact) -> serde_json::Value {
    let role = match value.role {
        ArtifactRole::Primary => "primary",
        ArtifactRole::Captions => "captions",
        ArtifactRole::Artwork => "artwork",
        ArtifactRole::Metadata => "metadata",
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
        ("Unsupported", "unsupported"),
        ("NotFound", "not_found"),
        ("Authentication", "authentication"),
        ("RateLimited", "rate_limited"),
        ("Unavailable", "unavailable"),
        ("InvalidData", "invalid_data"),
        ("Failed", "failed"),
    ]
    .into_iter()
    .find_map(|(variant, code)| message.contains(variant).then_some(code))
    .unwrap_or("plugin_error")
}

fn broadcast_option(
    request: InputOptionRequest,
) -> broadcast_world::exports::stashd::plugin::broadcast_plugin::Setting {
    broadcast_world::exports::stashd::plugin::broadcast_plugin::Setting {
        key: request.key,
        value: match request.value {
            InputOptionValueRequest::Boolean(value) => {
                broadcast_world::exports::stashd::plugin::broadcast_plugin::OptionValue::Boolean(
                    value,
                )
            }
            InputOptionValueRequest::Text(value) => {
                broadcast_world::exports::stashd::plugin::broadcast_plugin::OptionValue::Text(value)
            }
        },
    }
}

fn invoke_broadcast(
    engine: &Engine,
    component: &Component,
    staging_dir: PathBuf,
    request: BroadcastPublishRequest,
) -> Result<(
    broadcast_world::exports::stashd::plugin::broadcast_plugin::Publication,
    BroadcastState,
)> {
    let mut store = Store::new(
        engine,
        BroadcastState {
            table: ResourceTable::new(),
            wasi: WasiCtxBuilder::new().build(),
            progress: Vec::new(),
            logs: Vec::new(),
            staging_dir,
        },
    );
    let mut linker = Linker::new(engine);
    wasmtime_wasi::p2::add_to_linker_sync(&mut linker)?;
    broadcast_world::BroadcastWorld::add_to_linker::<_, HasSelf<_>>(&mut linker, |state| state)?;
    let plugin = broadcast_world::BroadcastWorld::instantiate(&mut store, component, &linker)?;
    let request =
        broadcast_world::exports::stashd::plugin::broadcast_plugin::PublishRequest {
            reference: request.reference,
            settings: request.settings.into_iter().map(broadcast_option).collect(),
            items: request
                .items
                .into_iter()
                .map(|item| {
                    broadcast_world::exports::stashd::plugin::broadcast_plugin::Item {
                    id: item.id,
                    title: item.title,
                    description: item.description,
                    published_at: item.published_at,
                    duration_seconds: item.duration_seconds,
                        resources: item.resources.into_iter().map(|resource| {
                        broadcast_world::exports::stashd::plugin::broadcast_plugin::ItemResource {
                            reference: resource.reference,
                            kind: resource.kind,
                            url: resource.url,
                            media_type: resource.media_type,
                            size_bytes: resource.size_bytes,
                        }
                        }).collect(),
                    }
                })
                .collect(),
        };
    let result = plugin
        .stashd_plugin_broadcast_plugin()
        .call_publish(&mut store, &request)?
        .map_err(|error| anyhow::anyhow!("broadcast plugin error: {error:?}"))?;
    Ok((result, store.into_data()))
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
                request.options.unwrap_or_default(),
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
                    .then_some(request.source.as_deref())
                    .flatten(),
                (request.op == "input-discover")
                    .then_some(request.input_id.as_deref())
                    .flatten(),
                request.intent.as_deref(),
                request.http_grants,
                request.options,
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
        "broadcast-publish" => {
            let component_path = request
                .component_path
                .as_deref()
                .context("broadcast invocation requires component_path")?;
            let staging_dir = request
                .staging_dir
                .clone()
                .context("broadcast invocation requires staging_dir")?;
            let component = Component::from_file(engine, component_path)
                .with_context(|| format!("loading component {}", component_path.display()))?;
            let (publication, state) = match invoke_broadcast(
                engine,
                &component,
                staging_dir,
                request.broadcast.context("broadcast request is required")?,
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
            for stage in state.progress {
                send(
                    stream,
                    Response::Progress {
                        id: request.id.clone(),
                        fraction: 0.0,
                        stage,
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
                Response::BroadcastPublished {
                    id: request.id,
                    publication: serde_json::json!({
                        "artifact": {
                            "reference": publication.artifact.reference,
                            "media_type": publication.artifact.media_type,
                            "size_bytes": publication.artifact.size_bytes,
                        },
                        "published_metadata": publication.published_metadata.iter().map(|setting| {
                            serde_json::json!({
                                "key": setting.key,
                                "value": match &setting.value {
                                    broadcast_world::exports::stashd::plugin::broadcast_plugin::OptionValue::Boolean(value) => serde_json::json!(value),
                                    broadcast_world::exports::stashd::plugin::broadcast_plugin::OptionValue::Text(value) => serde_json::json!(value),
                                },
                            })
                        }).collect::<Vec<_>>(),
                    }),
                },
            )?;
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
    use super::{CredentialGrant, HttpGrant, authenticated_url, input_host};

    #[test]
    fn input_http_capability_uses_invocation_supplied_prefix_grants() {
        let grants = [HttpGrant {
            allowed_prefixes: vec!["https://source.invalid/feed".to_owned()],
            credential: None,
        }];
        assert!(authenticated_url("https://source.invalid/feed/items", None, &grants).is_ok());
        assert!(matches!(
            authenticated_url("https://source.invalid/private", None, &grants),
            Err(input_host::HttpError::Denied)
        ));
        assert!(matches!(
            authenticated_url("http://source.invalid/feed", None, &grants),
            Err(input_host::HttpError::Denied)
        ));
    }

    #[test]
    fn credential_use_is_bound_to_the_invocation_grant_and_prefix() {
        let grants = [HttpGrant {
            allowed_prefixes: vec!["https://api.invalid/v1/".to_owned()],
            credential: Some(CredentialGrant {
                name: "provider-key".to_owned(),
                value: "fixture-secret".to_owned(),
                query_parameter: "token".to_owned(),
            }),
        }];
        let url = authenticated_url(
            "https://api.invalid/v1/items?id=demo",
            Some("provider-key"),
            &grants,
        )
        .expect("approved credential use should succeed");
        assert!(url.contains("token=fixture-secret"));
        assert!(matches!(
            authenticated_url("https://api.invalid/v1/items?id=x", None, &grants),
            Err(input_host::HttpError::CredentialUnavailable)
        ));
        assert!(matches!(
            authenticated_url(
                "https://other.invalid/v1/items?id=x",
                Some("provider-key"),
                &grants
            ),
            Err(input_host::HttpError::Denied)
        ));
        assert!(matches!(
            authenticated_url(
                "http://api.invalid/v1/items?id=x",
                Some("provider-key"),
                &grants
            ),
            Err(input_host::HttpError::Denied)
        ));
        assert!(matches!(
            authenticated_url(
                "https://api.invalid/v1/items?id=x",
                Some("other-key"),
                &grants
            ),
            Err(input_host::HttpError::CredentialUnavailable)
        ));
    }

    #[test]
    fn credential_grant_does_not_authorize_a_public_request() {
        let grants = [HttpGrant {
            allowed_prefixes: vec!["https://api.invalid/v1/".to_owned()],
            credential: Some(CredentialGrant {
                name: "provider-key".to_owned(),
                value: "fixture-secret".to_owned(),
                query_parameter: "token".to_owned(),
            }),
        }];
        assert!(matches!(
            authenticated_url("https://api.invalid/v1/items?id=x", None, &grants),
            Err(input_host::HttpError::CredentialUnavailable)
        ));
    }
}
