wit_bindgen::generate!({
    path: "../../plugin-api/spike-wit",
    world: "plugin-world",
});

use exports::stashd::plugin::plugin::{Guest, PluginError, RunResult as PluginResult};
use stashd::plugin::host::{self, Operation, StagingOutput, VaultAsset};

struct ExamplePlugin;

impl Guest for ExamplePlugin {
    fn run(
        asset: &VaultAsset,
        staging: &StagingOutput,
        operation: Operation,
    ) -> Result<PluginResult, PluginError> {
        host::report_progress(&host::Progress {
            fraction: 0.0,
            stage: "starting".to_owned(),
        });
        host::log("example plugin received its invocation grant");

        if matches!(operation, Operation::TypedFailure) {
            return Err(PluginError::Rejected("requested typed failure".to_owned()));
        }

        if matches!(operation, Operation::Trap) {
            panic!("deliberate example plugin trap");
        }

        let source_bytes = VaultAsset::size(asset);
        host::report_progress(&host::Progress {
            fraction: 0.5,
            stage: "reading asset".to_owned(),
        });

        let mut offset = 0;
        while offset < source_bytes {
            let maximum = (source_bytes - offset).min(1024) as u32;
            let chunk = VaultAsset::read(asset, offset, maximum).map_err(|error| {
                PluginError::HostFailure(format!("asset read failed: {error:?}"))
            })?;
            let chunk_len = chunk.len() as u64;
            StagingOutput::write(staging, &chunk).map_err(|error| {
                PluginError::HostFailure(format!("staging write failed: {error:?}"))
            })?;
            offset += chunk_len;
        }

        host::report_progress(&host::Progress {
            fraction: 0.8,
            stage: "writing output".to_owned(),
        });
        let output = StagingOutput::finish(staging).map_err(|error| {
            PluginError::HostFailure(format!("staging finish failed: {error:?}"))
        })?;
        host::report_progress(&host::Progress {
            fraction: 1.0,
            stage: "complete".to_owned(),
        });

        Ok(PluginResult {
            output_id: output.id,
            output_bytes: output.bytes,
            source_bytes,
        })
    }
}

export!(ExamplePlugin);
