use std::fs;
use std::io::{BufRead, BufReader, Read, Write};
use std::os::unix::net::{UnixListener, UnixStream};
use std::path::{Path, PathBuf};

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

mod youtube_input_world {
    wasmtime::component::bindgen!({
        path: "../plugin-api/wit",
        world: "youtube-input-world",
        with: {
            "stashd:plugin/input-host/http-client": super::HttpClientResource,
        },
    });
}

use youtube_input_world::YoutubeInputWorld;
use youtube_input_world::exports::stashd::plugin::input_plugin::{DiscoveredItem, ResolvedInput};
use youtube_input_world::stashd::plugin::input_host::{
    self as input_host, Host as InputHost, HostHttpClient,
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
        url: String,
    ) -> Result<input_host::HttpResponse, input_host::HttpError> {
        let _ = self
            .table
            .get(&client)
            .map_err(|_| input_host::HttpError::Failed("HTTP capability expired".to_owned()))?;
        if !allowed_youtube_url(&url) {
            return Err(input_host::HttpError::Denied);
        }

        if let Some(directory) = &self.fixture_dir {
            let map_path = directory.join("map.json");
            let map: std::collections::HashMap<String, String> = serde_json::from_slice(
                &fs::read(&map_path)
                    .map_err(|error| input_host::HttpError::Failed(error.to_string()))?,
            )
            .map_err(|error| input_host::HttpError::Failed(error.to_string()))?;
            let filename = map.get(&url).ok_or_else(|| {
                input_host::HttpError::Unavailable("fixture not found".to_owned())
            })?;
            let path = directory.join(filename);
            let body =
                fs::read(path).map_err(|error| input_host::HttpError::Failed(error.to_string()))?;
            let status = if filename == "unavailable.txt" {
                404
            } else {
                200
            };
            return Ok(input_host::HttpResponse { status, body });
        }

        let response = ureq::get(&url)
            .call()
            .map_err(|error| input_host::HttpError::Unavailable(error.to_string()))?;
        let status = response.status() as u16;
        let mut body = Vec::new();
        response
            .into_reader()
            .read_to_end(&mut body)
            .map_err(|error| input_host::HttpError::Failed(error.to_string()))?;
        Ok(input_host::HttpResponse { status, body })
    }

    fn drop(&mut self, client: Resource<HttpClientResource>) -> wasmtime::Result<()> {
        Ok(self.table.delete(client).map(|_| ())?)
    }
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

fn invoke_input(
    engine: &Engine,
    component: &Component,
    fixture_dir: Option<PathBuf>,
    source_uri: Option<&str>,
    channel_id: Option<&str>,
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
        (
            None,
            Some(
                plugin
                    .stashd_plugin_input_plugin()
                    .call_discover(&mut store, client, channel)?
                    .map_err(|error| anyhow::anyhow!("plugin input error: {error:?}"))?,
            ),
        )
    };
    Ok((resolved, items, store.into_data()))
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
    })
}

fn plugin_error_code(message: &str) -> &'static str {
    [
        ("UnsupportedSource", "unsupported_source"),
        ("ChannelResolutionFailed", "channel_resolution_failed"),
        ("UpstreamUnavailable", "upstream_unavailable"),
        ("MalformedFeed", "malformed_feed"),
        ("SourceNotFound", "source_not_found"),
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
    use super::allowed_youtube_url;

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
}
