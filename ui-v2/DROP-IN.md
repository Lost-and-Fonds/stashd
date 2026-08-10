# Drop-in instructions

From wherever you extracted this archive:

```bash
cp -R ui-v2 /path/to/stashd/ui-v2
cd /path/to/stashd
```

Then start Claude Code from the Stashd repository root and paste the contents of:

```text
ui-v2/prompts/01-FIRST-SCAFFOLD.md
```

The UI v2 project intentionally has its own `package.json` and `node_modules`. Do not merge its dependencies into Stashd's root `package.json` during the design phase.

To run it yourself:

```bash
cd ui-v2
npm install
npm run dev
```
