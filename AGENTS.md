# Working on this repo

This repo is the asset bundle consumed by `spark lab` in [spark-cli](https://github.com/jalendport/spark-cli) — Craft skeletons, Docker context, and the in-Craft `lab` module. **Read `ENGINE.md` before changing assets**: it's the contract with the engine, and asset changes that alter what the engine consumes must update it too. Where prose and code disagree, the engine's code is authoritative. (`README.md` is for plugin authors *using* the lab — don't put contributor docs there.)

Invariants and quirks:

- `skeleton/craft-4/` and `skeleton/craft-5/` deliberately duplicate each other. `modules/lab/src/Module.php` must stay **byte-identical** across the two; only `SeedController.php` may differ (it's written against each major's APIs). If you edit one copy, sync the other.
- There is no runtime here — no PHP on the host, nothing to run in this repo. Verify changes by setting `SPARK_LAB_ASSETS` to this checkout and running `spark lab up` inside a Craft plugin repo.
- Assets are baked into instances at mint. Edits never affect existing instances; picking up a change means `spark lab destroy` + `up`.
- `.tpl` files contain `{{PLACEHOLDER}}` tokens rendered by the engine via string replacement (see ENGINE.md §5) — they're not broken templating.
