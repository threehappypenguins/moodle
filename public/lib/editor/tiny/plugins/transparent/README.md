# TinyMCE Transparent Text

Moodle TinyMCE plugin (`tiny_transparent`) that marks selected text as transparent. The text stays in the document (selectable, readable by assistive tech) but is hidden when the content is viewed on the site.

## Requirements

- Moodle 5.2–5.3
- TinyMCE editor (`editor_tiny`)

A zip named `tiny_transparent.zip` should contain a top-level `transparent/` folder (with `version.php` inside it), not a nested `tiny_transparent/` directory.

## Usage

In any TinyMCE field:

- Select text and click the **Transparent text** toolbar button (in the formatting group, after italic), or
- Choose **Format → Transparent text**, or
- Press **Ctrl+M** (Windows/Linux) or **Cmd+M** (macOS).

The same control toggles the format off. Undo/redo works as with other editor commands.

While editing, transparent text stays faintly visible with a dotted underline so you can still find it. On the site it is fully hidden, including nested links or coloured spans.

Saved markup looks like:

```html
<span class="tiny-transparent-text">hidden text</span>
```

## Permissions

Access is controlled by the capability `tiny/transparent:use`, allowed for the `user` archetype by default. Prohibit it on a role to hide the button and Format menu item for those users.

## License

GNU GPL v3 or later.