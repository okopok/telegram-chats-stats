## Description

<!-- What does this PR do? Link the issue it fixes, if any. -->

## Checklist

- [ ] New statistics follow the project conventions (`key()`, `description()` in Russian,
      template in `src/templates/default/handlers/`, registration in `GenerateCommand::handlers()`).
- [ ] New aggregations were added to `MessageCollection::aggregate()` (single pass),
      no extra full iterations over messages.
- [ ] Docs updated (`docs/` sections affected by the change — the documentation rule).
- [ ] Verified with a quick run: `php bin/chatstats generate <export> --key=check --limit=2000`
- [ ] Existing panels still render (checked old-format export as well, if touched the parser).

## Screenshots

<!-- If the change affects the generated page, attach screenshots (light/dark theme). -->