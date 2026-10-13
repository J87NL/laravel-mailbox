# Handoff: TranquilTools changes needed by the J87 Mailbox

Status 2026-10-13: items 1–9 shipped in laravel-table-builder 1.0.0, laravel-vue-table-builder 1.9.0, laravel-form-builder 1.7.0 and laravel-vue-form-builder 1.10.0; J87 Mailbox 0.2.0 uses them. Item 10 (default editor) and item 11 (server-side searchable select) are open.

The J87 Mailbox (`j87nl/laravel-mailbox`, `j87nl/laravel-vue-mailbox`) builds on `laravel-form-builder`, `laravel-vue-form-builder` and `laravel-vue-table-builder`. Building it exposed gaps in those packages. Each change below is generic (useful outside the mailbox), backward compatible, and fits a minor release. Facts about the current code were checked against the repositories as of October 2026.

Priority: **P1** blocks a usable mailbox v0.1, **P2** improves it noticeably, **P3** is structural.

## Overview

| # | Package | Change | Priority |
|---|---|---|---|
| 1 | vue-table-builder | Bulk actions with a choice (e.g. "Move to folder") | P1 |
| 2 | form-builder (+ Vue) | `Tags` field with email mode and suggestions | P1 |
| 3 | vue-table-builder | Auto-refresh (`poll`) | P2 |
| 4 | vue-table-builder | Server-side `rowClass` | P2 |
| 5 | form-builder (+ Vue) | `Files::current()` with a list of existing files | P2 |
| 6 | form-builder (+ Vue) | Editor-agnostic Wysiwyg profiles (no Tiptap dependency) | P2 |
| 7 | new: laravel-table-builder | Extract a frontend-agnostic table core | P3 |
| 8 | new: shared installer | Extract the Vite/tsconfig patchers from the CrudBuilder | P3 |
| 9 | vue-table-builder / vue-form-builder | Translation bundles for third-party packages | P3 |
| 10 | form-builder | Replace Quill as default editor (choice open) | Major |
| 11 | form-builder (+ Vue) | `Select` with server-side search (async options) | P2 |

---

## 1. Bulk actions with a choice — P1

**Why.** `bulkAction()` only accepts callbacks and confirm texts (`BulkAction` has no input). "Move selected messages to folder X" is impossible, so the mailbox only offers moving from the single-message view. The same need exists elsewhere: "assign to user", "set status".

**Proposed API.**

```php
$table->bulkAction(
    label: 'Move to',
    before: fn (array $ids, ?string $choice) => $move->handle($ids, $choice),
    choices: $folders->pluck('path', 'id')->all(),
    choicesLabel: 'Folder',
);
```

- `choices` is `array<string|int, string>`; when present, the confirm dialog renders a select and the request carries `choice`.
- `BulkActionRequest` validates `choice` with `Rule::in(array_keys($choices))` server-side; choices are re-resolved from the table on the server, never trusted from the client.
- Callbacks receive `$choice` as an extra argument; existing callbacks with one parameter keep working (PHP ignores the extra argument for closures declared with fewer parameters — verify for `callable` strings and invokables, otherwise use reflection to decide).

**Done when.** A table can define a bulk action with choices, the Vue dialog shows a select, invalid choices are rejected with 422, and the existing bulk-action tests still pass.

## 2. `Tags` field with email mode — P1

**Why.** To/Cc/Bcc are now comma-separated `Text` fields plus a custom rule. Users expect chips, paste-splitting and address suggestions. A generic tags field is also useful for labels and keywords.

**Proposed API.**

```php
Tags::make('to')
    ->emails()
    ->suggestions(route('mailbox.addresses'))
    ->required();
```

- Value is `array<string>`; `normalizeValue()` also accepts a comma/semicolon/newline separated string, for backward compatibility and pasting.
- `emails()` adds the rules `array` and `*.email:rfc` (wildcard rules via `nestedRules()`, like `HasRemoveFlag` does) and makes the renderer split on `, ; \n` and validate each chip.
- `suggestions(string $url)` makes the renderer fetch `GET {url}?q=` and expect `[{ value, label }]`, debounced, keyboard-navigable (reka-ui Combobox fits the existing UI kit).
- `max(int)` limits the number of chips.

**Done when.** The field round-trips arrays, accepts pasted lists, validates every entry, and works without `suggestions()`.

## 3. Auto-refresh — P2

**Why.** New mail must appear without a manual reload. Dashboards and queues have the same need.

**Proposed API.** `$table->poll(30)` emits `poll: 30` in the payload. `TableBuilder.vue` calls `router.reload({ only: [propName], preserveScroll: true, preserveState: true })` on that interval, pauses while `document.hidden` or while rows are selected or a dialog is open, and resumes on `visibilitychange`.

**Done when.** Polling does not reset selection, sorting, search or pagination, and stops when the component unmounts.

## 4. Server-side `rowClass` — P2

**Why.** `TableBuilder.vue` already accepts a `rowClass` prop. That only works if the page author passes a closure, so published or generated pages (CrudBuilder) cannot use it, and the logic lives far from the table class.

**Proposed API.** `$table->rowClass(fn (MailboxMessage $message): string|array => $message->is_seen ? '' : 'font-semibold')`. The result is emitted per row as `__class`; the component merges it with the JS prop (JS prop applied last).

**Done when.** Classes from PHP and the prop combine, and tables without `rowClass` produce no extra payload.

## 5. `Files::current()` with existing files — P2

**Why.** `File::current(?string $url, ?string $name)` and `removable()` cover one existing file. Forwarding a message with its attachments, editing drafts, or any "documents" form needs a list of existing files with per-file removal.

**Proposed API.**

```php
Files::make('attachments')->current([
    ['key' => '0', 'name' => 'offerte.pdf', 'size' => 81234, 'url' => $url],
])->removable();
```

- Removal posts `remove_attachments[]` with the keys; `removalRequested()` gets a sibling `removedKeys(): array`.
- The renderer shows existing files above the upload input, each with a remove toggle.

**Done when.** Existing files render, removal keys arrive validated (`in:` the provided keys), and single `File::current()` keeps working.

## 6. Editor-agnostic Wysiwyg profiles — P2

**Current state (checked).** `laravel-vue-form-builder` main has the editor registry (`registerWysiwygEditor`) with Quill, Jodit, HugeRTE and Textarea adapters, and `default-editor` is `quill`. There is **no Tiptap adapter**: the `tranquil-tools/laravel-vue-editor` package planned in `HANDOFF-editor-refactor.md` was never built. The J87 Mailbox therefore must not depend on Tiptap, and does not: `ComposeForm` sets its own editor with `Wysiwyg::editor(config('mailbox.compose.editor'))` (default `hugerte`) and passes the matching email-safe option set from `mailbox.compose.editor_options.<editor>` through `Wysiwyg::options()`. The compose editor is independent of the host app's `default-editor`, so there is no conflict with apps that pin another editor. No interim change is needed in the packages.

**Why a change is still useful.** Options are editor-specific arrays, so the mailbox has to keep a separate option set per editor (HugeRTE and Quill today), and every other consumer that wants "a restricted editor" has to know each editor's config format. Quill also expresses alignment and indentation as CSS classes (`ql-align-*`, `ql-indent-*`), which disappear in mail clients; that is why the mailbox defaults to HugeRTE.

**Proposed API.** `Wysiwyg::make('body')->profile('email')`. A profile is a semantic feature list in config (`form-builder.wysiwyg.profiles.email = ['bold', 'italic', 'underline', 'link', 'list', 'blockquote']`); each adapter maps the list to its own toolbar/extension config. An adapter that cannot honour a feature leaves it out. Any future adapter (Tiptap or otherwise) implements the same mapping, and consumers switch editor without touching their forms.

**Done when.** Quill, Jodit and HugeRTE adapters support profiles, `profile()` combined with `options()` lets options win, and the docs list which features each adapter supports.

## 7. Extract `laravel-table-builder` (agnostic core) — P3

**Why.** `AbstractTable` and `TableBuilder` live in the Vue package, so table definitions cannot live in a frontend-agnostic package. The J87 Mailbox core therefore has no table; a future React or Livewire renderer would have to redefine it. The FormBuilder already went through this split.

**Approach.** Same pattern as `laravel-form-builder`: move `src/` (builder, components, concerns, export, routing, HTTP controllers for bulk/reorder/export) to `tranquil-tools/laravel-table-builder`, keep the namespace `TranquilTools\TableBuilder`, and add a `SCHEMA_VERSION` emitted as `schemaVersion` in the payload, like `FormConfig::SCHEMA_VERSION`. `laravel-vue-table-builder` then requires the core and ships only `resources/js` plus its service provider.

**Done when.** `composer require tranquil-tools/laravel-vue-table-builder` keeps working unchanged for existing apps, and the core has no reference to Vue, Inertia or `resources/js`.

## 8. Shared installer support — P3

**Why.** `ViteConfigPatcher`, `TsConfigPatcher` and `DetectsPagesDirectory` live in the CrudBuilder. The mailbox needs the same thing for its `@mailbox` alias and page publishing; copying them means three diverging copies.

**Approach.** Move them into the form-builder core (`TranquilTools\FormBuilder\Support\Installer`) or a small `tranquil-tools/laravel-installer`. Offer a reusable command trait:

```php
$this->installAliases(['@mailbox' => './vendor/j87nl/laravel-vue-mailbox/resources/js']);
$this->installTsPaths(['@mailbox/*' => './vendor/j87nl/laravel-vue-mailbox/resources/js/*']);
```

**Done when.** `crud-builder:install` uses the extracted classes with identical behaviour, and a third-party package can add aliases with two calls.

## 9. Translation bundles for third-party packages — P3

**Why.** The table builder's `useTranslations(key)` reads a shared Inertia prop. There is no documented way for another package to register its own bundle, so the mailbox Vue pages currently hardcode Dutch.

**Approach.** Document (and if needed expose) a helper such as `TableBuilder::shareTranslations('vue_mailbox_translations', 'mailbox::mailbox')` that adds the bundle to the shared Inertia props for the current locale, and make `useTranslations` importable from one stable path.

**Done when.** The mailbox pages can replace every hardcoded string with `t('...')` without touching the host app's `HandleInertiaRequests`.

## 10. Quill is no longer the default editor — decision open, major release

**Decision so far.** Quill stops being the FormBuilder default; it stays available as an adapter (not deprecated). The replacement is **not decided yet**. The Tiptap package from `HANDOFF-editor-refactor.md` (`tranquil-tools/laravel-vue-editor`) was never built, so "default becomes `'tiptap'`" is not an option today.

**Options.**

| Option | For | Against |
|---|---|---|
| A. HugeRTE as default | Adapter exists; MIT; ships its own UI; inline styles; one Quill migration | Heavy bundle; dated look; depends on a community fork of TinyMCE 6 |
| B. Build the full editor package (refactor phases 3–4) | Manifest, paste cleaner, uploads, JSON storage | Weeks of work; has not happened in 2.5 months |
| C. Bare Tiptap adapter in form-builder | Modern, fits the Vue/reka-ui look | Tiptap is headless, so a toolbar must be built; scope tends to grow |

**Interim policy.** Apps pin their editor in a published `config/form-builder.php` (`'default-editor' => 'hugerte'`, or whatever they choose) and register that adapter. Pinned apps are unaffected by whatever the package default becomes. First consumer: the Trefpunt booking app (also the first app using the J87 Mailbox), which pins `hugerte`.

**What the eventual major contains, whichever option wins.**

1. The new `form-builder.wysiwyg.default-editor`; the Quill entry stays under `editors`.
2. UPGRADE.md: apps that want to keep Quill set `'default-editor' => 'quill'` or publish the config before upgrading.
3. A content migration for existing Quill HTML (`ql-align-*`, `ql-indent-*`, empty `<p><br></p>`) as an artisan command taking model/column pairs, with `--dry-run`.
4. The Pest build-fixture guards from refactor phase 2: no editor package and no Wysiwyg field keeps the build green; a Wysiwyg field without registration shows the clear error.

Changing the default is breaking for apps that never published the config, because they inherit it silently; that is why this is a major regardless of the option.

**Small related cleanup.** The HugeRTE adapter passes `license_key: 'gpl'`. That is a TinyMCE 7 setting; HugeRTE is an MIT fork of TinyMCE 6. Verify whether HugeRTE reads it at all, and drop it if not, so nobody concludes from the code that the adapter carries GPL obligations.

---

## 11. `Select` with server-side search — P2

**Why.** `Select::options()` takes the full option list and sends it to the browser. The mailbox access form needs "user or group", so every manager of one mailbox receives the names of all app users and groups, and the list is built (all users plus all groups) on every render. In larger apps that is both a privacy leak and a performance problem. The mailbox now memoises the list per request, but cannot avoid sending it.

**Proposed API.**

```php
Select::make('accessor')
    ->searchUrl(route('mailbox.settings.accessors', $mailbox))
    ->minSearchLength(2)
    ->rules('required', new ExistingAccessor);
```

- `searchUrl(string $url)` makes the renderer fetch `GET {url}?q=` (debounced) and expect `[{ value, label }]`; no `options` are sent up front.
- The current value is sent as `selected: { value, label }` so an edit form can show it without a search.
- Validation stays server-side (an `exists`-style rule); the field adds no `in:` rule because the option list is not known.

**Done when.** A searchable select works without `options()`, sends only matches, and existing selects with `options()` behave as before.

## Release notes

Items 1–9 and 11 are additive; item 10 is the only breaking change and waits for the default-editor decision. Suggested bundling: items 1, 3 and 4 in one `laravel-vue-table-builder` minor; items 2, 5 and 6 in one `laravel-form-builder` / `laravel-vue-form-builder` minor; items 7–9 as a separate structural release. Document every new API on docs.comfycoders.nl before tagging, since that is the single documentation source for these packages.
