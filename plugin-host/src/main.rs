use std::fs;
use std::io::{BufRead, BufReader, Write};
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

impl WasiView for HostState {
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
