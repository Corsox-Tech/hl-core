# Email System v2 — Track 2: Email Builder Enhancements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add columns editing, undo/redo, fullscreen preview modal, and text formatting to the email builder.

**Architecture:** Extend existing `HL_Admin_Email_Builder` + `email-builder.js`. Maintain JSON format backward compatibility — new properties on text block (`text_align`, `font_size`) and new split values on columns block. `HL_Email_Block_Renderer` reads new properties as inline table-cell styles for email client compatibility.

**Tech Stack:** PHP 7.4+, WordPress 6.0+, jQuery, Sortable.js (CDN-loaded), Dashicons.

---

## Spec References

Primary spec: `docs/superpowers/specs/2026-04-10-email-system-v2-design.md`

Track 2 items:
- **2.1 Columns Block Editing** — §2.1 (lines 339-394)
- **2.2 Undo / Redo** — §2.2 (lines 396-452)
- **2.3 Preview Modal** — §2.3 (lines 454-496)
- **2.4 Text Alignment + Font Size** — §2.4 (lines 499-550)

Appendix A items addressed here: A.1.1, A.1.3, A.1.4, A.2.1, A.2.2, A.2.3, A.2.4, A.2.5, A.2.11, A.3.1, A.3.17, A.6.5, A.6.9, A.6.15, A.6.16, A.6.17, A.7.8, A.7.9, A.7.14.

---

## File Structure

### Files Created

| Path | Purpose |
|------|---------|
| `tests/test-email-block-renderer.php` | PHPUnit-free test harness (loaded via WP-CLI command `wp hl-core test-email-renderer`). Asserts columns 5-split widths, text alignment, font-size inline rules, Outlook inner `<span>`. |
| `includes/cli/class-hl-cli-test-email-renderer.php` | Thin WP-CLI wrapper that bootstraps and runs the above assertions. |
| `docs/superpowers/fixtures/email-track2-samples.json` | Sample blocks JSON fixtures used by manual smoke tests and the renderer test. |

### Files Modified

| Path | Changes |
|------|---------|
| `includes/admin/class-hl-admin-email-builder.php` | Add Preview button, modal markup, undo toolbar markup, toolbar hint text; add `ajax_preview_render()` POST handling + CSP + `dark` param; localize user meta (`undo_notice_seen_{template_id}`) on render; add `ajax_dismiss_undo_notice()` handler. |
| `includes/services/class-hl-email-block-renderer.php` | Update `render_text()` for `text_align` + `font_size` (`<td>` and inner `<span>`); add `get_column_widths()` for 5 splits; update `render_columns()` to use it. |
| `assets/js/admin/email-builder.js` | Add: undo/redo stack, sortable registry, nested column rendering, nested mini palette, move-to-other-column, text block alignment/font-size UI, preview modal (replace sidebar iframe), `srcdoc`, focus preservation, autosave-race debounce. |
| `assets/css/admin.css` | New v2 section: `.hl-eb-undo-group`, `.hl-eb-col-*`, `.hl-eb-block-nested`, `.hl-eb-mini-palette`, `.hl-eb-modal*`, `.hl-eb-align-group`, `.hl-eb-size-select`, `.hl-eb-undo-notice`. |
| `hl-core.php` | Register new CLI test command (CLI section only). |

### Responsibility Boundaries

- **email-builder.js** owns: all interactive mutation, snapshot capture, modal lifecycle, nested rendering, sortable management. **Single file** (475 lines grows to ~1200). We do NOT split it into modules — the existing codebase uses single-file plugin JS and introducing a module system is out of scope.
- **class-hl-admin-email-builder.php** owns: page markup (server-rendered shells that JS mounts into), AJAX endpoints, nonce + cap checks.
- **class-hl-email-block-renderer.php** owns: pure block → HTML translation, including the new text/column properties.
- **test-email-block-renderer.php** is a plain PHP file containing an `HL_Test_Email_Block_Renderer` class with static assertion methods. No PHPUnit dependency.

---

## Conventions

- **PHP:** WordPress coding standards. Class prefix `HL_`. Escape output (`esc_html`, `esc_attr`, `esc_url`) even inside admin.
- **JS:** jQuery IIFE wrapper (`jQuery(function($){...})`). No arrow functions, no `const`/`let` — stay consistent with existing file's `var` usage so the style stays uniform. All new functions defined inside the existing closure.
- **CSS:** Use existing `--eb-*` custom properties. No `!important`. New class prefix `.hl-eb-` (continuing existing convention).
- **Sortable.js:** already CDN-loaded by the existing `initSortable()` bootstrap. All new instances must be stored in a global `sortableRegistry` array and `.destroy()` called before every `renderAllBlocks()` to avoid handle collisions (A.1.1, A.2.1).
- **Snapshot format:** `JSON.parse(JSON.stringify(blocks))`. No structured clone — IE fallback not needed (admin-only, supports evergreen browsers).
- **Commits:** After each task that produces working code, commit with a descriptive message.

---

## Build Order

1. **Task A** — Scaffolding: sortable registry + pushUndo stub + fixtures + test harness (prep work that later tasks depend on).
2. **Task B** — 2.4 Text Alignment + Font Size (smallest, no dependencies on other Track 2 items; exercises the test harness).
3. **Task C** — 2.2 Undo / Redo (stack, buttons, Ctrl+Z/Y, autosave debounce, undo-clear notice).
4. **Task D** — 2.1 Columns Block Editing (nested render, per-column sortable, mini palette, move-to-other-column, 5 splits, renderer update).
5. **Task E** — 2.3 Preview Modal (fullscreen modal, device toggles, srcdoc, sandbox, dark backdrop, CSP, focus trap, loading skeleton).

Commit after each task. Self-test at each step before moving on.

---

## Task A — Scaffolding (Registry, Fixtures, Test Harness)

**Files:**
- Create: `tests/test-email-block-renderer.php`
- Create: `includes/cli/class-hl-cli-test-email-renderer.php`
- Create: `docs/superpowers/fixtures/email-track2-samples.json`
- Modify: `hl-core.php` (CLI registration)
- Modify: `assets/js/admin/email-builder.js` (sortable registry + pushUndo stub)

### Why this task exists

The renderer and builder changes are testable via WP-CLI. We need the harness before writing PHP changes so we can follow TDD. We also need a sortable registry early so later tasks (columns, undo) can register instances safely without touching this scaffolding again.

### A.1 Create fixtures file

- [ ] **Step A.1.1: Create the fixtures JSON**

Create `docs/superpowers/fixtures/email-track2-samples.json` with exactly this content:

```json
{
  "text_default": [
    { "type": "text", "content": "<p>Hello world.</p>" }
  ],
  "text_aligned_center": [
    { "type": "text", "content": "<p>Centered.</p>", "text_align": "center" }
  ],
  "text_aligned_right_sized": [
    { "type": "text", "content": "<p>Big right.</p>", "text_align": "right", "font_size": 20 }
  ],
  "text_invalid_align": [
    { "type": "text", "content": "<p>x</p>", "text_align": "justify", "font_size": 999 }
  ],
  "columns_60_40": [
    { "type": "columns", "split": "60/40",
      "left":  [{ "type": "text", "content": "<p>L</p>" }],
      "right": [{ "type": "text", "content": "<p>R</p>" }] }
  ],
  "columns_33_67": [
    { "type": "columns", "split": "33/67",
      "left":  [{ "type": "text", "content": "<p>L</p>" }],
      "right": [{ "type": "button", "label": "Go", "url": "https://example.com" }] }
  ],
  "columns_invalid_split": [
    { "type": "columns", "split": "70/30",
      "left":  [], "right": [] }
  ]
}
```

- [ ] **Step A.1.2: Commit fixtures**

```bash
git add docs/superpowers/fixtures/email-track2-samples.json
git commit -m "test(email): add Track 2 block fixtures"
```

### A.2 Create renderer test harness

- [ ] **Step A.2.1: Create `tests/test-email-block-renderer.php`**

```php
<?php
/**
 * Email Block Renderer — Track 2 test harness.
 *
 * Plain PHP assertions (no PHPUnit). Invoked via:
 *   wp hl-core test-email-renderer
 *
 * @package HL_Core
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class HL_Test_Email_Block_Renderer {

    /** @var int Passed assertion count. */
    public static $passed = 0;

    /** @var array Failure messages. */
    public static $failures = array();

    /**
     * Run all test methods. Returns true if all passed.
     *
     * @return bool
     */
    public static function run_all() {
        self::$passed   = 0;
        self::$failures = array();

        self::test_text_default_has_no_align_override();
        self::test_text_center_emits_inline_td_style();
        self::test_text_right_with_font_size_emits_both();
        self::test_text_invalid_values_fall_back_to_defaults();
        self::test_text_font_size_emits_on_inner_span_for_outlook();
        self::test_columns_split_50_50();
        self::test_columns_split_60_40();
        self::test_columns_split_40_60();
        self::test_columns_split_33_67();
        self::test_columns_split_67_33();
        self::test_columns_invalid_split_falls_back_to_50_50();

        return empty( self::$failures );
    }

    /** Assertion helper: substring contained. */
    private static function assert_contains( $haystack, $needle, $label ) {
        if ( strpos( $haystack, $needle ) !== false ) {
            self::$passed++;
            return;
        }
        self::$failures[] = $label . ' — expected to find: ' . $needle;
    }

    /** Assertion helper: substring NOT contained. */
    private static function assert_not_contains( $haystack, $needle, $label ) {
        if ( strpos( $haystack, $needle ) === false ) {
            self::$passed++;
            return;
        }
        self::$failures[] = $label . ' — did NOT expect: ' . $needle;
    }

    /** Load a fixture by key from the samples file. */
    private static function fixture( $key ) {
        $path = dirname( __DIR__ ) . '/docs/superpowers/fixtures/email-track2-samples.json';
        $json = file_get_contents( $path );
        $all  = json_decode( $json, true );
        return $all[ $key ] ?? array();
    }

    /** Render a block array to HTML (blocks-only, no shell). */
    private static function render( array $blocks ) {
        $renderer = HL_Email_Block_Renderer::instance();
        return $renderer->render_blocks_only( $blocks, array() );
    }

    // =====================================================================
    // Text block assertions
    // =====================================================================

    public static function test_text_default_has_no_align_override() {
        $html = self::render( self::fixture( 'text_default' ) );
        // Default alignment: the <td> must NOT contain "text-align:center" or "text-align:right".
        self::assert_not_contains( $html, 'text-align:center', 'text_default' );
        self::assert_not_contains( $html, 'text-align:right',  'text_default' );
    }

    public static function test_text_center_emits_inline_td_style() {
        $html = self::render( self::fixture( 'text_aligned_center' ) );
        self::assert_contains( $html, 'text-align:center', 'text_aligned_center' );
    }

    public static function test_text_right_with_font_size_emits_both() {
        $html = self::render( self::fixture( 'text_aligned_right_sized' ) );
        self::assert_contains( $html, 'text-align:right', 'text_aligned_right_sized align' );
        self::assert_contains( $html, 'font-size:20px',   'text_aligned_right_sized size' );
    }

    public static function test_text_invalid_values_fall_back_to_defaults() {
        $html = self::render( self::fixture( 'text_invalid_align' ) );
        // text_align "justify" not in allowlist → falls back to left (no alignment style emitted)
        self::assert_not_contains( $html, 'text-align:justify', 'text_invalid_align' );
        // font_size 999 clamped to 48
        self::assert_contains( $html, 'font-size:48px', 'text_invalid_align clamp' );
    }

    public static function test_text_font_size_emits_on_inner_span_for_outlook() {
        // A.3.1: Outlook Word-engine ignores <td> font-size — require inner <span>.
        $html = self::render( self::fixture( 'text_aligned_right_sized' ) );
        self::assert_contains( $html, '<span style="font-size:20px', 'text inner span for outlook' );
    }

    // =====================================================================
    // Columns block assertions
    // =====================================================================

    public static function test_columns_split_50_50() {
        $html = self::render( array(
            array( 'type' => 'columns', 'split' => '50/50', 'left' => array(), 'right' => array() )
        ) );
        self::assert_contains( $html, 'width:50%', 'columns 50/50' );
    }

    public static function test_columns_split_60_40() {
        $html = self::render( self::fixture( 'columns_60_40' ) );
        self::assert_contains( $html, 'width:60%', 'columns 60/40 left' );
        self::assert_contains( $html, 'width:40%', 'columns 60/40 right' );
    }

    public static function test_columns_split_40_60() {
        $html = self::render( array(
            array( 'type' => 'columns', 'split' => '40/60', 'left' => array(), 'right' => array() )
        ) );
        self::assert_contains( $html, 'width:40%', 'columns 40/60 left' );
        self::assert_contains( $html, 'width:60%', 'columns 40/60 right' );
    }

    public static function test_columns_split_33_67() {
        $html = self::render( self::fixture( 'columns_33_67' ) );
        self::assert_contains( $html, 'width:33%', 'columns 33/67 left' );
        self::assert_contains( $html, 'width:67%', 'columns 33/67 right' );
    }

    public static function test_columns_split_67_33() {
        $html = self::render( array(
            array( 'type' => 'columns', 'split' => '67/33', 'left' => array(), 'right' => array() )
        ) );
        self::assert_contains( $html, 'width:67%', 'columns 67/33 left' );
        self::assert_contains( $html, 'width:33%', 'columns 67/33 right' );
    }

    public static function test_columns_invalid_split_falls_back_to_50_50() {
        $html = self::render( self::fixture( 'columns_invalid_split' ) );
        self::assert_contains( $html, 'width:50%', 'columns invalid split fallback' );
    }
}
```

- [ ] **Step A.2.2: Create CLI wrapper `includes/cli/class-hl-cli-test-email-renderer.php`**

```php
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CLI command: run the email block renderer test harness.
 *
 * Usage:
 *   wp hl-core test-email-renderer
 *
 * @package HL_Core
 */
class HL_CLI_Test_Email_Renderer {

    public static function register() {
        WP_CLI::add_command( 'hl-core test-email-renderer', array( new self(), 'run' ) );
    }

    public function run() {
        require_once dirname( __DIR__, 2 ) . '/tests/test-email-block-renderer.php';

        $ok = HL_Test_Email_Block_Renderer::run_all();

        WP_CLI::line( sprintf( 'Passed: %d', HL_Test_Email_Block_Renderer::$passed ) );

        if ( ! $ok ) {
            foreach ( HL_Test_Email_Block_Renderer::$failures as $msg ) {
                WP_CLI::warning( $msg );
            }
            WP_CLI::error( sprintf( 'FAIL: %d failure(s).', count( HL_Test_Email_Block_Renderer::$failures ) ) );
        }

        WP_CLI::success( 'All renderer assertions passed.' );
    }
}

HL_CLI_Test_Email_Renderer::register();
```

- [ ] **Step A.2.3: Register the CLI command in `hl-core.php`**

Edit `hl-core.php`. Find the CLI block (line ~250, inside `if ( defined( 'WP_CLI' ) && WP_CLI )`) and append one line after the existing `require_once` calls, before the closing brace:

```php
            require_once HL_CORE_INCLUDES_DIR . 'cli/class-hl-cli-test-email-renderer.php';
```

Place it after `class-hl-cli-sync-ld-enrollment.php`.

- [ ] **Step A.2.4: Run the test harness — expect ALL assertions to FAIL**

Deploy the files to the test server (or run locally with WP-CLI). From the test server:

```bash
wp hl-core test-email-renderer
```

Expected: 0–2 passes, many failures. The renderer has not been changed yet, so:
- Text alignment assertions fail (renderer ignores `text_align`).
- Font size assertions fail (renderer ignores `font_size`).
- Columns 40/60, 33/67, 67/33 all fall back to 50/50 (only 50/50 and 60/40 supported in v1).
- Invalid split still lands on 50/50 (that one passes).
- Inner `<span>` for font size fails.

This is the "red" phase — confirm the harness is wired up before proceeding.

### A.3 Add sortable registry + pushUndo stub to builder JS

- [ ] **Step A.3.1: Add top-of-file state (inside the IIFE, after `var sortableInstance = null;`)**

Open `assets/js/admin/email-builder.js`. Find the line `var sortableInstance = null;` (around line 16). Replace it and the following blank line with:

```javascript
    var sortableInstance = null;
    // A.1.1 / A.2.1 — every Sortable instance ever created is tracked here so we can destroy
    // all of them before each renderAllBlocks() to avoid handle collisions / stale refs.
    var sortableRegistry = [];

    // Undo / redo state (populated in Task C, stubbed here so earlier tasks can call pushUndo safely).
    var undoStack = [];
    var redoStack = [];
    var MAX_UNDO  = 50;

    // Autosave-race debounce window after an undo/redo (A.2.4). 5000ms.
    var autosaveSuppressUntil = 0;
```

- [ ] **Step A.3.2: Add stub functions near the bottom of the IIFE (just above `function escHtml`)**

Add these helpers so later tasks can call them — full implementations land in Task C:

```javascript
    // =========================================================================
    // Undo / Redo  (stub implementations — real logic in Task C)
    // =========================================================================

    function pushUndo() {
        // Deep clone current state into the undo stack.
        undoStack.push(JSON.parse(JSON.stringify(blocks)));
        if (undoStack.length > MAX_UNDO) {
            undoStack.shift();
        }
        redoStack = [];
        updateUndoButtons();
    }

    function updateUndoButtons() {
        // Will be fully implemented in Task C — safe no-op if elements don't exist yet.
        var $undo = $('#hl-eb-undo');
        var $redo = $('#hl-eb-redo');
        if ($undo.length) { $undo.prop('disabled', undoStack.length === 0); }
        if ($redo.length) { $redo.prop('disabled', redoStack.length === 0); }
    }

    function destroyAllSortables() {
        sortableRegistry.forEach(function (inst) {
            try { inst.destroy(); } catch (e) {}
        });
        sortableRegistry = [];
        sortableInstance = null;
    }
```

- [ ] **Step A.3.3: Replace the existing `sortableInstance.destroy()` call in `initSortable()` with the registry destroyer**

Find `initSortable()`. Replace:

```javascript
        if (sortableInstance) sortableInstance.destroy();
        sortableInstance = new Sortable(el, {
```

with:

```javascript
        destroyAllSortables();
        sortableInstance = new Sortable(el, {
            // A.1.1: distinct handle class for top-level, filter out nested blocks so the
            // top-level Sortable cannot intercept drags that belong to a nested column.
            filter: '.hl-eb-block-nested',
            preventOnFilter: false,
```

Also, inside the `new Sortable(el, { ... })` options object, change `handle: '.hl-eb-block-type'` to `handle: '.hl-eb-block-type:not(.hl-eb-block-type-nested)'`. Then register the instance:

```javascript
        sortableRegistry.push(sortableInstance);
```

Insert that `push` call at the end of `initSortable()`, after `sortableInstance = new Sortable(...)`.

- [ ] **Step A.3.4: Call `destroyAllSortables()` at the top of `renderAllBlocks()`**

Find `function renderAllBlocks() {` and immediately after the opening brace, insert:

```javascript
        destroyAllSortables();
```

- [ ] **Step A.3.5: Manual smoke — deploy and load builder page**

Deploy the JS, open the builder page on the test server, add and reorder a few top-level blocks, confirm no console errors and that top-level drag-reorder still works. Sortable registry should contain exactly 1 instance after first render.

- [ ] **Step A.3.6: Commit Task A**

```bash
git add tests/test-email-block-renderer.php \
        includes/cli/class-hl-cli-test-email-renderer.php \
        docs/superpowers/fixtures/email-track2-samples.json \
        hl-core.php \
        assets/js/admin/email-builder.js
git commit -m "test(email): add renderer test harness + builder scaffolding for Track 2

- Add wp hl-core test-email-renderer WP-CLI command
- Add Track 2 block fixtures
- Add sortable registry + pushUndo stubs in builder JS
- Wire registry into renderAllBlocks / initSortable

Prep for text formatting, undo/redo, columns editing, preview modal."
```

---

## Task B — Text Block Alignment + Font Size (Spec §2.4)

**Files:**
- Modify: `includes/services/class-hl-email-block-renderer.php`
- Modify: `assets/js/admin/email-builder.js`
- Modify: `assets/css/admin.css`
- Modify: `tests/test-email-block-renderer.php` (already has the assertions — Task A wrote them)

**Appendix items:** A.2.11 (default left active state), A.3.1 (Outlook inner span).

### B.1 Update PHP renderer `render_text()`

- [ ] **Step B.1.1: Confirm assertions for text block are currently failing**

Run: `wp hl-core test-email-renderer`
Expected output includes failures for `text_aligned_center`, `text_aligned_right_sized`, `text_invalid_align clamp`, `text inner span for outlook`.

- [ ] **Step B.1.2: Rewrite `render_text()` in the renderer**

Open `includes/services/class-hl-email-block-renderer.php`. Find `private function render_text( array $block, array $merge_tags ) {` and replace the entire method body with:

```php
    private function render_text( array $block, array $merge_tags ) {
        $content = $block['content'] ?? '';
        $content = $this->substitute_tags( $content, $merge_tags );

        // Alignment — allowlist only. Default "left" means emit no alignment (inherit).
        $align_raw = isset( $block['text_align'] ) ? (string) $block['text_align'] : 'left';
        $align     = in_array( $align_raw, array( 'left', 'center', 'right' ), true ) ? $align_raw : 'left';

        // Font size — clamp to 10..48 px. Default 16 means "no explicit size".
        $has_size = isset( $block['font_size'] );
        $size     = $has_size ? max( 10, min( 48, (int) $block['font_size'] ) ) : 16;

        // Build <td> inline style.
        $td_style  = 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;';
        $td_style .= 'line-height:1.6;color:#374151;padding:0 0 16px;';
        if ( $align !== 'left' ) {
            $td_style .= 'text-align:' . $align . ';';
        }
        // Emit font-size on <td> for all clients except Outlook Word engine.
        $td_style .= 'font-size:' . $size . 'px;';

        // A.3.1 — Outlook Word engine ignores <td> font-size. Wrap content in a <span>
        // that emits font-size on the inline element. Always emit (cheap, harmless for non-Outlook).
        $open_span  = '<span style="font-size:' . $size . 'px;line-height:1.6;color:#374151;">';
        $close_span = '</span>';

        return '<table role="presentation" cellpadding="0" cellspacing="0" width="100%">'
            . '<tr><td class="hl-email-text" style="' . $td_style . '">'
            . $open_span . $content . $close_span
            . '</td></tr></table>';
    }
```

**Note:** This changes the text block wrapper from `<div>` to `<table><tr><td>`. Email clients require table-cell layout for reliable alignment — a `<div>` with `text-align` does not respect `text-align:center` in Outlook.

- [ ] **Step B.1.3: Run the test harness — expect text assertions to pass**

```bash
wp hl-core test-email-renderer
```

Expected: text_default, text_aligned_center, text_aligned_right_sized, text_invalid_align, text inner span for outlook **PASS**. Columns assertions still fail (Task D).

- [ ] **Step B.1.4: Visually regression-check the existing "Send test" path if available**

On the test server, open an existing template in the builder, click "Send Test Email" (or use an existing preview), and confirm text blocks still render correctly in Gmail/Outlook web. No visual change expected for templates without `text_align`/`font_size` set.

### B.2 Extend builder JS mini-toolbar

- [ ] **Step B.2.1: Replace the text case in `renderBlock()`**

Open `assets/js/admin/email-builder.js`. Find `case 'text':` in `renderBlock()`. Replace the entire `case 'text':` through the `break;` with:

```javascript
            case 'text':
                var $editor = $('<div class="hl-eb-text-editor" contenteditable="true"></div>');
                $editor.html(block.content || '<p>Enter text here...</p>');

                // Apply block-level text-align (CSS only — NOT via execCommand).
                var currentAlign = block.text_align || 'left';
                $editor.css('text-align', currentAlign);

                // Apply block-level font-size (CSS only).
                var currentSize = block.font_size || 16;
                $editor.css('font-size', currentSize + 'px');

                // Text snapshot debounce — push to undo stack on blur OR 2s idle after last keystroke.
                var textSnapshotTimer = null;
                var textSnapshotPending = false;
                function flushTextSnapshot() {
                    if (textSnapshotPending) {
                        textSnapshotPending = false;
                        clearTimeout(textSnapshotTimer);
                        textSnapshotTimer = null;
                        // The snapshot is the *pre-mutation* state — already captured when
                        // textSnapshotPending was first set. pushUndo() captures current state
                        // which is the post-mutation state; instead we store the pre-state in
                        // a closure variable below.
                    }
                }
                var preMutationText = null;
                $editor.on('focus', function () {
                    preMutationText = JSON.parse(JSON.stringify(blocks));
                });
                $editor.on('input', function () {
                    blocks[index].content = $(this).html();
                    markDirty();
                    runHealthCheck();
                    if (!textSnapshotPending) {
                        textSnapshotPending = true;
                    }
                    clearTimeout(textSnapshotTimer);
                    textSnapshotTimer = setTimeout(function () {
                        if (textSnapshotPending && preMutationText) {
                            undoStack.push(preMutationText);
                            if (undoStack.length > MAX_UNDO) undoStack.shift();
                            redoStack = [];
                            preMutationText = JSON.parse(JSON.stringify(blocks));
                            updateUndoButtons();
                        }
                        textSnapshotPending = false;
                    }, 2000);
                });
                $editor.on('blur', function () {
                    if (textSnapshotPending && preMutationText) {
                        undoStack.push(preMutationText);
                        if (undoStack.length > MAX_UNDO) undoStack.shift();
                        redoStack = [];
                        updateUndoButtons();
                    }
                    textSnapshotPending = false;
                    clearTimeout(textSnapshotTimer);
                });

                // Mini toolbar: B | I | link | align | font-size | merge tags
                var $miniBar = $(
                    '<div class="hl-eb-mini-toolbar">' +
                        '<button type="button" data-cmd="bold" title="Bold"><b>B</b></button>' +
                        '<button type="button" data-cmd="italic" title="Italic"><i>I</i></button>' +
                        '<button type="button" data-cmd="createLink" title="Link">&#x1F517;</button>' +
                        '<span class="hl-eb-mini-sep"></span>' +
                        '<div class="hl-eb-align-group" role="group" aria-label="Text alignment">' +
                            '<button type="button" class="hl-eb-align" data-align="left"   title="Align left"><span class="dashicons dashicons-editor-alignleft"></span></button>' +
                            '<button type="button" class="hl-eb-align" data-align="center" title="Align center"><span class="dashicons dashicons-editor-aligncenter"></span></button>' +
                            '<button type="button" class="hl-eb-align" data-align="right"  title="Align right"><span class="dashicons dashicons-editor-alignright"></span></button>' +
                        '</div>' +
                        '<span class="hl-eb-mini-sep"></span>' +
                        '<select class="hl-eb-size-select" title="Font size">' +
                            '<option value="12">12px</option>' +
                            '<option value="14">14px</option>' +
                            '<option value="16">16px</option>' +
                            '<option value="18">18px</option>' +
                            '<option value="20">20px</option>' +
                            '<option value="24">24px</option>' +
                        '</select>' +
                        '<span class="hl-eb-mini-sep"></span>' +
                        '<select class="hl-eb-merge-tag-select"><option value="">Insert tag...</option></select>' +
                    '</div>'
                );

                // Mark the currently-active alignment button (A.2.11 — default "left" is active when text_align absent).
                $miniBar.find('.hl-eb-align[data-align="' + currentAlign + '"]').addClass('active');
                // Set the font size select to current block value.
                $miniBar.find('.hl-eb-size-select').val(String(currentSize));

                // Populate merge tag dropdown.
                var $select = $miniBar.find('.hl-eb-merge-tag-select');
                var groups = config.mergeTagsGrouped || {};
                for (var cat in groups) {
                    var $group = $('<optgroup label="' + escHtml(cat) + '"></optgroup>');
                    for (var key in groups[cat]) {
                        $group.append('<option value="{{' + escHtml(key) + '}}">{{' + escHtml(key) + '}}</option>');
                    }
                    $select.append($group);
                }

                // Bold / italic / link (existing behavior).
                $miniBar.find('[data-cmd]').on('click', function (e) {
                    e.preventDefault();
                    pushUndo();
                    var cmd = $(this).data('cmd');
                    if (cmd === 'createLink') {
                        var url = prompt('Enter URL:');
                        if (url) document.execCommand('createLink', false, url);
                    } else {
                        document.execCommand(cmd, false, null);
                    }
                    blocks[index].content = $editor.html();
                    markDirty();
                });

                // Alignment buttons (radio group, block-level via CSS).
                $miniBar.find('.hl-eb-align').on('click', function (e) {
                    e.preventDefault();
                    pushUndo();
                    var align = $(this).data('align');
                    $miniBar.find('.hl-eb-align').removeClass('active');
                    $(this).addClass('active');
                    if (align === 'left') {
                        delete blocks[index].text_align;
                    } else {
                        blocks[index].text_align = align;
                    }
                    $editor.css('text-align', align);
                    markDirty();
                });

                // Font size select (block-level via CSS).
                $miniBar.find('.hl-eb-size-select').on('change', function () {
                    pushUndo();
                    var val = parseInt($(this).val(), 10);
                    if (val === 16) {
                        delete blocks[index].font_size;
                    } else {
                        blocks[index].font_size = val;
                    }
                    $editor.css('font-size', val + 'px');
                    markDirty();
                });

                // Merge tag insert (existing behavior).
                $select.on('change', function () {
                    var tag = $(this).val();
                    if (tag) {
                        document.execCommand('insertText', false, tag);
                        blocks[index].content = $editor.html();
                        $(this).val('');
                        markDirty();
                    }
                });

                $wrap.append($miniBar).append($editor);
                break;
```

- [ ] **Step B.2.2: Ensure Dashicons CSS is enqueued on the builder page**

Open `includes/admin/class-hl-admin-email-builder.php`. In `render()`, find the section that enqueues assets (`wp_enqueue_media()` etc., around line 69). After `wp_enqueue_script( 'wp-color-picker' );` add:

```php
        wp_enqueue_style( 'dashicons' );
```

### B.3 Add CSS for alignment buttons and mini-toolbar separators

- [ ] **Step B.3.1: Append a v2 section to `assets/css/admin.css`**

Open `assets/css/admin.css` and append the following at the very bottom of the file:

```css
/* =================================================================== */
/* Email System v2 — Track 2 Builder Enhancements                      */
/* =================================================================== */

/* --- Mini toolbar separators & alignment group ------------------- */
.hl-eb-mini-toolbar {
    display: flex;
    align-items: center;
    gap: 4px;
    flex-wrap: wrap;
}

.hl-eb-mini-sep {
    display: inline-block;
    width: 1px;
    height: 20px;
    background: var(--eb-border-med);
    margin: 0 4px;
}

.hl-eb-align-group {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    padding: 2px;
    background: var(--eb-bg-subtle);
    border: 1px solid var(--eb-border);
    border-radius: var(--eb-radius-sm);
}

.hl-eb-align {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 26px;
    background: transparent;
    border: 1px solid transparent;
    border-radius: 4px;
    cursor: pointer;
    color: var(--eb-muted);
}

.hl-eb-align:hover {
    background: #fff;
    color: var(--eb-text);
}

.hl-eb-align.active {
    background: #fff;
    border-color: var(--eb-border-med);
    color: var(--eb-accent);
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}

.hl-eb-align .dashicons {
    font-size: 18px;
    width: 18px;
    height: 18px;
    line-height: 18px;
}

.hl-eb-size-select {
    height: 28px;
    font-size: 12px;
    padding: 0 4px;
    border: 1px solid var(--eb-border);
    border-radius: 4px;
    background: #fff;
}
```

### B.4 Smoke + commit

- [ ] **Step B.4.1: Deploy and manual smoke**

Deploy. Open the builder on the test server. Add a text block. Click center align — text center aligns visually. Click right → right. Click left → default active state. Change font size dropdown to 20 → text renders at 20px in-editor. Save the template, reload, confirm state persists.

- [ ] **Step B.4.2: Run the renderer test again to confirm no regression**

```bash
wp hl-core test-email-renderer
```

Text assertions should still PASS.

- [ ] **Step B.4.3: Send a test email to a real inbox (Gmail + Outlook)**

On the test server, open a template containing the newly-aligned text, use the existing "Send test" flow (if present) or manual queue run. Open in Gmail — alignment + size correct. Open in Outlook web / Outlook 2019 if available — alignment correct, font size correct (the inner `<span>` should compensate for Outlook's Word engine).

- [ ] **Step B.4.4: Commit Task B**

```bash
git add includes/services/class-hl-email-block-renderer.php \
        includes/admin/class-hl-admin-email-builder.php \
        assets/js/admin/email-builder.js \
        assets/css/admin.css
git commit -m "feat(email): text block alignment + font size (Track 2.4)

- Mini-toolbar: 3 alignment buttons + 6-step size dropdown
- Block-level (CSS), not selection-level (execCommand)
- Renderer emits inline td text-align + font-size
- Outlook: inner <span> wrapper for font-size (A.3.1)
- Default left active state when text_align absent (A.2.11)
- Text snapshot debounce: blur or 2s idle"
```

---

## Task C — Undo / Redo (Spec §2.2)

**Files:**
- Modify: `includes/admin/class-hl-admin-email-builder.php` (toolbar buttons + undo-clear notice + AJAX handler)
- Modify: `assets/js/admin/email-builder.js` (undo/redo UI + keyboard + autosave-race debounce + pushUndo call sites)
- Modify: `assets/css/admin.css` (undo group + notice styles)

**Appendix items:** A.2.1 (focus preservation, flush pending text snapshot on Ctrl+Z, Safari `beforeinput historyUndo`), A.2.4 (autosave race debounce), A.6.9 (moveNestedBlock pushes undo — addressed in Task D), A.6.16 (tooltips + hint), A.7.8 (one-time undo-clear notice), A.7.14 (notice per user per template).

### C.1 Add toolbar buttons + hint text to the builder page

- [ ] **Step C.1.1: Update PHP toolbar markup**

Open `includes/admin/class-hl-admin-email-builder.php`. Find the `<div class="hl-eb-toolbar">` block (around line 139). Replace it with:

```php
                        <div class="hl-eb-toolbar">
                            <div class="hl-eb-undo-group" role="group" aria-label="<?php esc_attr_e( 'Undo and redo', 'hl-core' ); ?>">
                                <button type="button" class="button" id="hl-eb-undo" disabled
                                    title="<?php esc_attr_e( 'Undo (Ctrl+Z) — Undo history clears on save', 'hl-core' ); ?>"
                                    aria-label="<?php esc_attr_e( 'Undo', 'hl-core' ); ?>">&#x21A9;</button>
                                <button type="button" class="button" id="hl-eb-redo" disabled
                                    title="<?php esc_attr_e( 'Redo (Ctrl+Y) — Undo history clears on save', 'hl-core' ); ?>"
                                    aria-label="<?php esc_attr_e( 'Redo', 'hl-core' ); ?>">&#x21AA;</button>
                            </div>
                            <button type="button" class="button button-primary" id="hl-eb-save"><?php esc_html_e( 'Save Template', 'hl-core' ); ?></button>
                            <button type="button" class="button" id="hl-eb-preview-btn">
                                <span class="dashicons dashicons-visibility" style="vertical-align:text-bottom;"></span>
                                <?php esc_html_e( 'Preview', 'hl-core' ); ?>
                            </button>
                            <span class="hl-eb-autosave-status" id="hl-eb-autosave-status"></span>
                        </div>
```

Note: Preview button is added now so the click handler installed in Task E has a stable element to bind to. The button currently is a no-op until Task E.

- [ ] **Step C.1.2: Add the one-time undo-clear notice placeholder inside the canvas header**

Still in `render()`, immediately after the `<div class="hl-eb-toolbar">...</div>` closing (one level up — inside `<div class="hl-eb-canvas-header">`), append:

```php
                        <?php
                        // A.7.8 / A.7.14 — one-time per (user, template) undo-clear notice.
                        // Meta key: hl_email_builder_undo_notice_seen_{template_id}
                        // Shown inline above the canvas; dismissed via AJAX.
                        $notice_tpl_id = $template_id ?: 0;
                        $notice_seen   = (bool) get_user_meta( get_current_user_id(), 'hl_email_builder_undo_notice_seen_' . $notice_tpl_id, true );
                        ?>
                        <div class="hl-eb-undo-notice"
                             id="hl-eb-undo-notice"
                             style="display:none;"
                             data-template-id="<?php echo (int) $notice_tpl_id; ?>">
                            <span><?php esc_html_e( 'Your undo history was cleared by saving. Undo only works within a single editing session.', 'hl-core' ); ?></span>
                            <button type="button" class="hl-eb-undo-notice-dismiss" aria-label="<?php esc_attr_e( 'Dismiss notice', 'hl-core' ); ?>">&times;</button>
                        </div>
                        <script>window.hlEmailUndoNoticeSeen = <?php echo $notice_seen ? 'true' : 'false'; ?>;</script>
```

- [ ] **Step C.1.3: Add the AJAX handler for dismissing the notice**

In `HL_Admin_Email_Builder::__construct()`, add one more hook after the existing `wp_ajax_hl_email_template_delete`:

```php
        add_action( 'wp_ajax_hl_email_builder_dismiss_undo_notice', array( $this, 'ajax_dismiss_undo_notice' ) );
```

Then add this method to the class (after `ajax_delete()`):

```php
    /**
     * Mark the per-template undo-clear notice as seen for the current user.
     * A.7.8 / A.7.14 — meta key hl_email_builder_undo_notice_seen_{template_id}.
     */
    public function ajax_dismiss_undo_notice() {
        check_ajax_referer( 'hl_email_builder', 'nonce' );
        if ( ! current_user_can( 'manage_hl_core' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        $template_id = (int) ( $_POST['template_id'] ?? 0 );
        update_user_meta(
            get_current_user_id(),
            'hl_email_builder_undo_notice_seen_' . $template_id,
            1
        );
        wp_send_json_success();
    }
```

### C.2 Implement full undo/redo in JS

- [ ] **Step C.2.1: Replace the stub `pushUndo` / `updateUndoButtons` with full versions**

Open `assets/js/admin/email-builder.js`. Replace the stub functions added in Task A with:

```javascript
    // =========================================================================
    // Undo / Redo
    // =========================================================================

    function pushUndo() {
        undoStack.push(JSON.parse(JSON.stringify(blocks)));
        if (undoStack.length > MAX_UNDO) {
            undoStack.shift();
        }
        redoStack = [];
        updateUndoButtons();
    }

    function undo() {
        if (!undoStack.length) return;
        flushPendingTextSnapshot();
        redoStack.push(JSON.parse(JSON.stringify(blocks)));
        blocks = undoStack.pop();
        // A.2.4 — delay next autosave 5s after an undo/redo to give a redo window.
        autosaveSuppressUntil = Date.now() + 5000;
        renderAllBlocks();
        markDirty();
        updateUndoButtons();
    }

    function redo() {
        if (!redoStack.length) return;
        flushPendingTextSnapshot();
        undoStack.push(JSON.parse(JSON.stringify(blocks)));
        blocks = redoStack.pop();
        autosaveSuppressUntil = Date.now() + 5000;
        renderAllBlocks();
        markDirty();
        updateUndoButtons();
    }

    function updateUndoButtons() {
        $('#hl-eb-undo').prop('disabled', undoStack.length === 0);
        $('#hl-eb-redo').prop('disabled', redoStack.length === 0);
    }

    // Pending text snapshot flush — text blocks debounce snapshot on input/blur;
    // Ctrl+Z must flush the pending pre-mutation snapshot first so undo works correctly.
    // The per-text-block closure stores its pending snapshot in window._hlPendingTextSnap.
    function flushPendingTextSnapshot() {
        if (window._hlPendingTextSnap) {
            undoStack.push(window._hlPendingTextSnap);
            if (undoStack.length > MAX_UNDO) undoStack.shift();
            redoStack = [];
            window._hlPendingTextSnap = null;
        }
    }

    function destroyAllSortables() {
        sortableRegistry.forEach(function (inst) {
            try { inst.destroy(); } catch (e) {}
        });
        sortableRegistry = [];
        sortableInstance = null;
    }
```

- [ ] **Step C.2.2: Update the text block snapshot logic to use the global pending slot**

Inside `renderBlock()` → `case 'text':`, replace the `var preMutationText = null;` and subsequent snapshot closures with code that publishes the pending snapshot to `window._hlPendingTextSnap`:

Find this block (from Task B):

```javascript
                var preMutationText = null;
                $editor.on('focus', function () {
                    preMutationText = JSON.parse(JSON.stringify(blocks));
                });
                $editor.on('input', function () {
                    blocks[index].content = $(this).html();
                    markDirty();
                    runHealthCheck();
                    if (!textSnapshotPending) {
                        textSnapshotPending = true;
                    }
                    clearTimeout(textSnapshotTimer);
                    textSnapshotTimer = setTimeout(function () {
                        if (textSnapshotPending && preMutationText) {
                            undoStack.push(preMutationText);
                            if (undoStack.length > MAX_UNDO) undoStack.shift();
                            redoStack = [];
                            preMutationText = JSON.parse(JSON.stringify(blocks));
                            updateUndoButtons();
                        }
                        textSnapshotPending = false;
                    }, 2000);
                });
                $editor.on('blur', function () {
                    if (textSnapshotPending && preMutationText) {
                        undoStack.push(preMutationText);
                        if (undoStack.length > MAX_UNDO) undoStack.shift();
                        redoStack = [];
                        updateUndoButtons();
                    }
                    textSnapshotPending = false;
                    clearTimeout(textSnapshotTimer);
                });
```

Replace with:

```javascript
                $editor.on('focus', function () {
                    // Capture pre-mutation snapshot into the global pending slot.
                    // flushPendingTextSnapshot() on Ctrl+Z / blur / 2s idle will commit it.
                    if (!window._hlPendingTextSnap) {
                        window._hlPendingTextSnap = JSON.parse(JSON.stringify(blocks));
                    }
                });
                $editor.on('input', function () {
                    blocks[index].content = $(this).html();
                    markDirty();
                    runHealthCheck();
                    clearTimeout(textSnapshotTimer);
                    textSnapshotTimer = setTimeout(function () {
                        // 2s idle → commit the pending pre-mutation snapshot.
                        flushPendingTextSnapshot();
                        updateUndoButtons();
                        // Seed a fresh pre-mutation snapshot for the next edit burst.
                        window._hlPendingTextSnap = JSON.parse(JSON.stringify(blocks));
                    }, 2000);
                });
                $editor.on('blur', function () {
                    clearTimeout(textSnapshotTimer);
                    flushPendingTextSnapshot();
                    updateUndoButtons();
                });
                // A.2.1 Safari — beforeinput historyUndo must flush pending snapshot first.
                $editor.on('beforeinput', function (e) {
                    var ev = e.originalEvent || e;
                    if (ev && (ev.inputType === 'historyUndo' || ev.inputType === 'historyRedo')) {
                        flushPendingTextSnapshot();
                        updateUndoButtons();
                    }
                });
```

Also remove the now-unused `var textSnapshotPending = false;` and `function flushTextSnapshot() { ... }` definitions from inside the text case — they were stubs in Task B and are superseded by this step.

- [ ] **Step C.2.3: Wire pushUndo() into all top-level mutation sites**

Find the `bindEvents()` function. For each handler that mutates `blocks`, insert a `pushUndo();` call at the top of the handler BEFORE the mutation. Specifically:

**Add block handler** — find `$(document).on('click', '.hl-eb-add-block', function () {`. At the very top of the handler body, add:

```javascript
            pushUndo();
```

**Delete block handler** — find `$(document).on('click', '.hl-eb-block-del', function () {`. Add at top:

```javascript
            pushUndo();
```

**Duplicate block handler** — find `$(document).on('click', '.hl-eb-block-dup', function () {`. Add at top:

```javascript
            pushUndo();
```

**Top-level Sortable reorder** — in `initSortable()`, inside the `onEnd` callback, add `pushUndo();` as the first line.

- [ ] **Step C.2.4: Wire pushUndo() into per-block property handlers**

For each `change`/`input` handler inside `renderBlock()` that mutates `blocks[index].*` (image alt, button label, button url, color picker change, spacer slider, divider controls if present, columns split), add `pushUndo();` at the top. Text block is already handled via the debounce/flush path.

**Image alt input** — find `$altInput.on('change', function () {` and add `pushUndo();` first.

**Image picker select** — find `frame.on('select', function () {` inside the image case and add `pushUndo();` first.

**Button label change** — find `$label.on('change', function () { blocks[index].label = ...` and convert to:

```javascript
                $label.on('change', function () { pushUndo(); blocks[index].label = $(this).val(); markDirty(); });
                $url.on('change',   function () { pushUndo(); blocks[index].url   = $(this).val(); markDirty(); });
```

**Button color picker** — find `$bgInput.wpColorPicker({ change: function (e, ui) {`. Replace the inner body with:

```javascript
                    $bgInput.wpColorPicker({ change: function (e, ui) {
                        pushUndo();
                        blocks[index].bg_color = ui.color.toString();
                        markDirty();
                    }});
```

**Spacer slider** — find the spacer case. `$slider.on('input', function () {` — this fires very frequently. Guard with a local flag to push only on first interaction in a drag:

```javascript
                var spacerDragStarted = false;
                $slider.on('mousedown touchstart', function () {
                    if (!spacerDragStarted) {
                        pushUndo();
                        spacerDragStarted = true;
                    }
                });
                $slider.on('mouseup touchend', function () {
                    spacerDragStarted = false;
                });
                $slider.on('input', function () {
                    var val = parseInt($(this).val());
                    blocks[index].height = val;
                    $spacerPreview.css('height', val + 'px');
                    markDirty();
                });
```

**Columns split select (top-level)** — find the columns case top-level `$splitSelect.on('change', function () {` and insert `pushUndo();` as the first line. (This will be rewritten more thoroughly in Task D; this change survives that rewrite.)

- [ ] **Step C.2.5: Bind undo/redo buttons + keyboard shortcuts**

In `bindEvents()`, after the existing handlers, append:

```javascript
        // Undo / redo buttons.
        $('#hl-eb-undo').on('click', function () { undo(); });
        $('#hl-eb-redo').on('click', function () { redo(); });

        // Keyboard shortcuts — global, but guarded against contenteditable / input focus
        // so the browser's native per-field undo keeps working.
        $(document).on('keydown', function (e) {
            var isMeta = e.ctrlKey || e.metaKey;
            if (!isMeta) return;

            var key = String.fromCharCode(e.which || e.keyCode).toLowerCase();
            var target = e.target || {};
            var tag    = (target.tagName || '').toLowerCase();
            var isEditable = tag === 'input' || tag === 'textarea' || tag === 'select' || target.isContentEditable;

            // Inside a contenteditable text editor: let the browser undo the text edits.
            // We do flush any pending snapshot so Ctrl+Z after snapshot still has the
            // pre-mutation state in our stack when focus next leaves the editor.
            if (isEditable) {
                if (key === 'z') {
                    flushPendingTextSnapshot();
                    updateUndoButtons();
                }
                return;
            }

            if (key === 'z' && !e.shiftKey) {
                e.preventDefault();
                undo();
            } else if (key === 'y' || (key === 'z' && e.shiftKey)) {
                e.preventDefault();
                redo();
            }
        });
```

### C.3 Autosave-race debounce + undo-clear notice display

- [ ] **Step C.3.1: Update `markDirty()` / `doAutosave()` to respect `autosaveSuppressUntil`**

Find `function markDirty() {`. Replace with:

```javascript
    function markDirty() {
        isDirty = true;
        clearTimeout(autosaveTimer);
        var delay = 3000;
        var remaining = autosaveSuppressUntil - Date.now();
        if (remaining > delay) {
            delay = remaining;
        }
        autosaveTimer = setTimeout(doAutosave, delay);

        // localStorage backup.
        try {
            localStorage.setItem('hl_email_draft_backup', JSON.stringify({
                blocks: blocks,
                subject: $('#hl-eb-subject').val(),
                name: $('#hl-eb-name').val()
            }));
        } catch (e) {}
    }
```

- [ ] **Step C.3.2: Clear undo on save + show one-time notice**

Find `function saveTemplate() {`. After the `$.post` callback, inside the `if (res.success) {` block, after the existing body add:

```javascript
                // A.7.8 — first save with a non-empty undo stack shows the one-time notice.
                var hadHistory = undoStack.length > 0 || redoStack.length > 0;
                undoStack = [];
                redoStack = [];
                updateUndoButtons();
                if (hadHistory && !window.hlEmailUndoNoticeSeen) {
                    $('#hl-eb-undo-notice').show();
                }
```

- [ ] **Step C.3.3: Wire the notice dismiss button**

Still in `bindEvents()`, append:

```javascript
        // Dismiss undo-clear notice — AJAX write to user meta so it does not reappear.
        $(document).on('click', '.hl-eb-undo-notice-dismiss', function () {
            var $notice = $(this).closest('.hl-eb-undo-notice');
            $notice.hide();
            window.hlEmailUndoNoticeSeen = true;
            $.post(config.ajaxUrl, {
                action: 'hl_email_builder_dismiss_undo_notice',
                nonce: config.nonce,
                template_id: $notice.data('template-id') || 0
            });
        });
```

### C.4 CSS for undo group and notice

- [ ] **Step C.4.1: Append to the Track 2 section in `assets/css/admin.css`**

Add below the B.3 CSS block:

```css
/* --- Undo / redo toolbar group ----------------------------------- */
.hl-eb-undo-group {
    display: inline-flex;
    margin-right: 8px;
}

.hl-eb-undo-group .button {
    margin-right: 2px;
}

.hl-eb-undo-group .button[disabled] {
    opacity: 0.45;
    cursor: not-allowed;
}

/* --- Undo-clear notice ------------------------------------------- */
.hl-eb-undo-notice {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin: 8px 0;
    padding: 8px 12px;
    background: #FFFBEB;
    border: 1px solid #FDE68A;
    border-radius: var(--eb-radius-sm);
    color: #92400E;
    font-size: 13px;
}

.hl-eb-undo-notice-dismiss {
    background: transparent;
    border: 0;
    font-size: 18px;
    line-height: 1;
    cursor: pointer;
    color: #92400E;
    padding: 0 4px;
}
```

### C.5 Smoke + commit

- [ ] **Step C.5.1: Manual smoke test**

Deploy. Open builder. Expected flow:
1. Open an empty template. Undo button disabled; redo disabled.
2. Add a text block. Undo button enables. Press Ctrl+Z. Block disappears. Undo disables, redo enables.
3. Ctrl+Y. Block reappears.
4. Click in the text editor, type "hello", wait 3 seconds. Ctrl+Z while focused in editor → browser undoes the typing (native). Click outside the editor. Ctrl+Z → full block state reverts to previous snapshot.
5. Add 5 blocks, delete 3, drag-reorder, change button color. All actions reversible via Ctrl+Z.
6. Save. Both buttons disable; undo-clear notice appears (first save). Click dismiss. Reload the page — notice does NOT reappear.
7. Fill the undo stack beyond 50 by adding 55 blocks. Verify oldest is dropped (only 50 undos available).

- [ ] **Step C.5.2: Test autosave-race debounce**

Add a block (autosave scheduled for 3s). Within 1s, press Ctrl+Z. Wait 5s (autosaveSuppressUntil window). Autosave should fire once, 5s after the undo — NOT 3s after the original add.

- [ ] **Step C.5.3: Commit Task C**

```bash
git add includes/admin/class-hl-admin-email-builder.php \
        assets/js/admin/email-builder.js \
        assets/css/admin.css
git commit -m "feat(email): undo/redo with 50-snapshot ring buffer (Track 2.2)

- Toolbar buttons: undo / redo with tooltips (A.6.16)
- Ctrl+Z / Ctrl+Y / Ctrl+Shift+Z shortcuts
- Text blocks: snapshot on blur / 2s idle; flush on Ctrl+Z
- Safari beforeinput historyUndo flush (A.2.1)
- Autosave suppressed 5s after undo/redo (A.2.4)
- Undo stack cleared on save with one-time per-user-per-template
  notice (A.7.8 / A.7.14)
- All mutation sites call pushUndo() first"
```

---

## Task D — Columns Block Editing (Spec §2.1)

**Files:**
- Modify: `includes/services/class-hl-email-block-renderer.php` (5-split support)
- Modify: `includes/admin/class-hl-admin-email-builder.php` (sanitize deep clone already recurses — verify)
- Modify: `assets/js/admin/email-builder.js` (nested render, per-column Sortable, mini palette, move-to-other-column)
- Modify: `assets/css/admin.css` (columns editor styles)

**Appendix items:** A.1.1 (Sortable nested collision), A.2.5 (move-to-other-column button), A.6.9 (moveNestedBlock pushes undo), A.7.9 (disabled Columns button in mini palette with tooltip).

### D.1 Renderer: 5-split support

- [ ] **Step D.1.1: Run tests — confirm columns assertions still fail**

```bash
wp hl-core test-email-renderer
```

Expected: columns 40/60, 33/67, 67/33 assertions FAIL. 50/50 and 60/40 PASS.

- [ ] **Step D.1.2: Add `get_column_widths()` helper + update `render_columns()`**

Open `includes/services/class-hl-email-block-renderer.php`. Find `render_columns()`. Replace the method body and add a private helper after it:

```php
    /**
     * Columns block — two-column layout that stacks on mobile.
     * Supports 5 splits: 50/50, 60/40, 40/60, 33/67, 67/33.
     */
    private function render_columns( array $block, array $merge_tags ) {
        $split = $block['split'] ?? '50/50';
        list( $left_width, $right_width ) = $this->get_column_widths( $split );

        $left_blocks  = $block['left']  ?? array();
        $right_blocks = $block['right'] ?? array();

        $left_html  = '';
        $right_html = '';
        foreach ( $left_blocks as $sub ) {
            if ( is_array( $sub ) && ! empty( $sub['type'] ) ) {
                $left_html .= $this->render_block( $sub, $merge_tags );
            }
        }
        foreach ( $right_blocks as $sub ) {
            if ( is_array( $sub ) && ! empty( $sub['type'] ) ) {
                $right_html .= $this->render_block( $sub, $merge_tags );
            }
        }

        $max      = self::MAX_WIDTH - 80; // Account for container padding.
        $left_px  = (int) round( $max * $left_width / 100 );
        $right_px = $max - $left_px;

        $html  = '<!--[if mso]><table role="presentation" cellpadding="0" cellspacing="0" width="100%"><tr><td width="' . $left_px . '" valign="top"><![endif]-->';
        $html .= '<div class="hl-email-col" style="display:inline-block;vertical-align:top;width:' . $left_width . '%;max-width:' . $left_px . 'px;">';
        $html .= $left_html;
        $html .= '</div>';
        $html .= '<!--[if mso]></td><td width="' . $right_px . '" valign="top"><![endif]-->';
        $html .= '<div class="hl-email-col" style="display:inline-block;vertical-align:top;width:' . $right_width . '%;max-width:' . $right_px . 'px;">';
        $html .= $right_html;
        $html .= '</div>';
        $html .= '<!--[if mso]></td></tr></table><![endif]-->';

        return '<div style="margin:0 0 16px;">' . $html . '</div>';
    }

    /**
     * Resolve a split label to integer column width percentages.
     * Falls back to [50, 50] for any unknown split.
     *
     * @param string $split Split label (e.g. "60/40").
     * @return int[] [left, right]
     */
    private function get_column_widths( $split ) {
        switch ( $split ) {
            case '60/40': return array( 60, 40 );
            case '40/60': return array( 40, 60 );
            case '33/67': return array( 33, 67 );
            case '67/33': return array( 67, 33 );
            case '50/50':
            default:      return array( 50, 50 );
        }
    }
```

- [ ] **Step D.1.3: Run tests — expect all columns assertions to PASS**

```bash
wp hl-core test-email-renderer
```

Expected: ALL 12 assertions PASS.

### D.2 Builder JS: nested rendering + mini palette + move-to-other-column

- [ ] **Step D.2.1: Replace the top-level `case 'columns':` in `renderBlock()`**

Find the existing `case 'columns':` in `renderBlock()` (the one that renders the placeholder dashed boxes). Replace with a single-line delegation:

```javascript
            case 'columns':
                return renderColumnsBlock(block, index);
```

Return early — the switch is inside `renderBlock()` which expects the function to `return $wrap` at the bottom. Wrap this specific case in a direct return that also attaches the outer toolbar. To keep the existing pattern (toolbar then content), instead do:

```javascript
            case 'columns':
                // Columns has its own renderer that builds the full wrap (with toolbar)
                // so we bail out of the default $wrap flow.
                return renderColumnsBlock(block, index);
```

And at the top of `renderBlock()`, change the end of the function from `return $wrap;` to still return `$wrap`. `renderColumnsBlock()` returns its own jQuery wrap.

- [ ] **Step D.2.2: Add `renderColumnsBlock()` and helpers after `renderBlock()`**

After `function renderBlock(block, index) { ... }`, add the following block of functions inside the IIFE:

```javascript
    // =========================================================================
    // Columns block (nested editing — Spec §2.1)
    // =========================================================================

    /** Default block factory used by both top-level palette and nested palette. */
    function makeDefaultBlock(type) {
        switch (type) {
            case 'text':    return { type: 'text',    content: '<p>Enter text here...</p>' };
            case 'image':   return { type: 'image',   src: '', alt: '', width: 300 };
            case 'button':  return { type: 'button',  label: 'Click Here', url: '', bg_color: '#2C7BE5', text_color: '#FFFFFF' };
            case 'divider': return { type: 'divider', color: '#E5E7EB', thickness: 1 };
            case 'spacer':  return { type: 'spacer',  height: 24 };
            case 'columns': return { type: 'columns', split: '50/50', left: [], right: [] };
            default:        return { type: type };
        }
    }

    function renderColumnsBlock(block, index) {
        var $wrap = $('<div class="hl-eb-block hl-eb-block-columns" data-index="' + index + '"></div>');

        // Header bar — matches other blocks but with the split selector added.
        var $toolbar = $(
            '<div class="hl-eb-block-toolbar">' +
                '<span class="hl-eb-block-type">Columns</span>' +
                '<span class="hl-eb-block-actions">' +
                    '<select class="hl-eb-col-split" title="Split ratio">' +
                        '<option value="50/50">50 / 50</option>' +
                        '<option value="60/40">60 / 40</option>' +
                        '<option value="40/60">40 / 60</option>' +
                        '<option value="33/67">33 / 67</option>' +
                        '<option value="67/33">67 / 33</option>' +
                    '</select>' +
                    '<button type="button" class="hl-eb-block-dup" title="Duplicate">&#x2398;</button>' +
                    '<button type="button" class="hl-eb-block-del" title="Delete">&times;</button>' +
                '</span>' +
            '</div>'
        );
        $toolbar.find('.hl-eb-col-split').val(block.split || '50/50');
        $toolbar.find('.hl-eb-col-split').on('change', function () {
            pushUndo();
            blocks[index].split = $(this).val();
            markDirty();
            renderAllBlocks();
        });
        $wrap.append($toolbar);

        // Two-column body.
        var widths = getColumnWidthsJS(block.split || '50/50');
        var $body = $('<div class="hl-eb-col-body" style="display:flex;gap:12px;"></div>');
        var $left  = renderColumnContainer(block.left  || [], index, 'left',  widths[0]);
        var $right = renderColumnContainer(block.right || [], index, 'right', widths[1]);
        $body.append($left).append($right);
        $wrap.append($body);

        return $wrap;
    }

    /** Mirror of PHP get_column_widths() for the JS side. */
    function getColumnWidthsJS(split) {
        switch (split) {
            case '60/40': return [60, 40];
            case '40/60': return [40, 60];
            case '33/67': return [33, 67];
            case '67/33': return [67, 33];
            case '50/50':
            default:      return [50, 50];
        }
    }

    function renderColumnContainer(colBlocks, parentIndex, side, widthPct) {
        var label = side === 'left' ? 'Left Column' : 'Right Column';
        var $col = $(
            '<div class="hl-eb-col" style="flex:' + widthPct + ' 1 0;"' +
                 ' data-parent="' + parentIndex + '" data-side="' + side + '">' +
                '<div class="hl-eb-col-label">' + escHtml(label) + ' (' + widthPct + '%)</div>' +
                '<div class="hl-eb-col-blocks"></div>' +
                '<div class="hl-eb-col-add-wrap">' +
                    '<button type="button" class="button button-small hl-eb-col-add-btn">+ Add Block</button>' +
                '</div>' +
            '</div>'
        );

        var $colBlocks = $col.find('.hl-eb-col-blocks');
        colBlocks.forEach(function (nested, colIndex) {
            $colBlocks.append(renderNestedBlock(nested, parentIndex, side, colIndex));
        });

        // Per-column Sortable — distinct group name prevents cross-column drag (A.1.1).
        setTimeout(function () {
            var el = $colBlocks.get(0);
            if (!el || typeof Sortable === 'undefined') return;
            var inst = new Sortable(el, {
                group: { name: 'col-' + parentIndex + '-' + side, pull: false, put: false },
                handle: '.hl-eb-drag-handle-nested',
                animation: 150,
                onEnd: function (evt) {
                    pushUndo();
                    var arr = blocks[parentIndex][side];
                    var item = arr.splice(evt.oldIndex, 1)[0];
                    arr.splice(evt.newIndex, 0, item);
                    markDirty();
                    renderAllBlocks();
                }
            });
            sortableRegistry.push(inst);
        }, 0);

        return $col;
    }

    function renderNestedBlock(block, parentIndex, side, colIndex) {
        var $wrap = $('<div class="hl-eb-block hl-eb-block-nested"' +
                      ' data-parent="' + parentIndex + '"' +
                      ' data-side="' + side + '"' +
                      ' data-col-index="' + colIndex + '"></div>');

        var $toolbar = $(
            '<div class="hl-eb-block-toolbar hl-eb-block-toolbar-nested">' +
                '<span class="hl-eb-block-type hl-eb-block-type-nested hl-eb-drag-handle-nested">' + escHtml(block.type) + '</span>' +
                '<span class="hl-eb-block-actions">' +
                    '<button type="button" class="hl-eb-nested-move" title="Move to other column">&#x21C4;</button>' +
                    '<button type="button" class="hl-eb-nested-dup"  title="Duplicate">&#x2398;</button>' +
                    '<button type="button" class="hl-eb-nested-del"  title="Delete">&times;</button>' +
                '</span>' +
            '</div>'
        );
        $wrap.append($toolbar);

        // Render the inner content by delegating to the same case bodies we use for
        // top-level blocks. We can't reuse renderBlock() directly because that function
        // assumes a top-level index. Instead, build a shim block accessor.
        var shim = new NestedBlockShim(parentIndex, side, colIndex);
        $wrap.append(renderNestedContent(block, shim));
        return $wrap;
    }

    /**
     * NestedBlockShim — proxies reads/writes of a nested block through to
     * blocks[parentIndex][side][colIndex], so all existing content renderers
     * can operate on it without caring whether the block is top-level or nested.
     */
    function NestedBlockShim(parentIndex, side, colIndex) {
        this.parentIndex = parentIndex;
        this.side = side;
        this.colIndex = colIndex;
    }
    NestedBlockShim.prototype.get = function () {
        return blocks[this.parentIndex][this.side][this.colIndex];
    };
    NestedBlockShim.prototype.set = function (key, value) {
        blocks[this.parentIndex][this.side][this.colIndex][key] = value;
    };
    NestedBlockShim.prototype.unset = function (key) {
        delete blocks[this.parentIndex][this.side][this.colIndex][key];
    };

    /**
     * Render the inner content (minus toolbar) of a nested block.
     * A compact variant of the renderBlock() switch — text/image/button/divider/spacer only.
     */
    function renderNestedContent(block, shim) {
        var $content = $('<div class="hl-eb-nested-content"></div>');
        switch (block.type) {
            case 'text':
                var $editor = $('<div class="hl-eb-text-editor hl-eb-text-editor-nested" contenteditable="true"></div>');
                $editor.html(block.content || '<p>Text...</p>');
                $editor.css('text-align', block.text_align || 'left');
                $editor.css('font-size',  (block.font_size || 14) + 'px');
                $editor.on('focus', function () {
                    if (!window._hlPendingTextSnap) {
                        window._hlPendingTextSnap = JSON.parse(JSON.stringify(blocks));
                    }
                });
                $editor.on('input', function () {
                    shim.set('content', $(this).html());
                    markDirty();
                });
                $editor.on('blur', function () {
                    flushPendingTextSnapshot();
                    updateUndoButtons();
                });
                $content.append($editor);
                break;
            case 'image':
                var cur = block.src
                    ? '<img src="' + escHtml(block.src) + '" style="max-width:100%;" />'
                    : '<em style="color:#999;">(no image)</em>';
                var $imgPreview = $('<div class="hl-eb-img-preview">' + cur + '</div>');
                var $imgBtn = $('<button type="button" class="button button-small hl-eb-choose-image-nested">Choose</button>');
                $imgBtn.on('click', function () {
                    var frame = wp.media({ title: 'Select Image', multiple: false, library: { type: 'image' } });
                    frame.on('select', function () {
                        pushUndo();
                        var a = frame.state().get('selection').first().toJSON();
                        if (/\.svg$/i.test(a.url)) { alert('SVG files are not allowed in emails.'); return; }
                        shim.set('src', a.url);
                        shim.set('width', Math.min(a.width || 300, 300));
                        $imgPreview.html('<img src="' + escHtml(a.url) + '" style="max-width:100%;" />');
                        markDirty();
                    });
                    frame.open();
                });
                $content.append($imgPreview).append($imgBtn);
                break;
            case 'button':
                var $lbl = $('<input type="text" placeholder="Label" value="' + escHtml(block.label || 'Click') + '">');
                var $u   = $('<input type="text" placeholder="URL" value="' + escHtml(block.url || '') + '">');
                $lbl.on('change', function () { pushUndo(); shim.set('label', $(this).val()); markDirty(); });
                $u.on('change',   function () { pushUndo(); shim.set('url',   $(this).val()); markDirty(); });
                $content.append($lbl).append($u);
                break;
            case 'divider':
                $content.append('<hr style="border-top:' + (block.thickness || 1) + 'px solid ' + escHtml(block.color || '#E5E7EB') + ';">');
                break;
            case 'spacer':
                var h = block.height || 16;
                var $sl = $('<input type="range" min="8" max="80" value="' + h + '">');
                var $pv = $('<div class="hl-eb-spacer-preview" style="height:' + h + 'px;background:#f0f0f0;"></div>');
                var spacerDragStartedN = false;
                $sl.on('mousedown touchstart', function () {
                    if (!spacerDragStartedN) { pushUndo(); spacerDragStartedN = true; }
                });
                $sl.on('mouseup touchend', function () { spacerDragStartedN = false; });
                $sl.on('input', function () {
                    var val = parseInt($(this).val());
                    shim.set('height', val);
                    $pv.css('height', val + 'px');
                    markDirty();
                });
                $content.append($sl).append($pv);
                break;
        }
        return $content;
    }

    // =========================================================================
    // Nested block action handlers (event delegation on .hl-eb-block-nested)
    // =========================================================================

    function nestedCoords($el) {
        var $b = $el.closest('.hl-eb-block-nested');
        return {
            parent:   parseInt($b.data('parent'), 10),
            side:     $b.data('side'),
            colIndex: parseInt($b.data('col-index'), 10)
        };
    }

    function deleteNestedBlock(parentIndex, side, colIndex) {
        pushUndo();
        blocks[parentIndex][side].splice(colIndex, 1);
        markDirty();
        renderAllBlocks();
    }

    function duplicateNestedBlock(parentIndex, side, colIndex) {
        pushUndo();
        var clone = JSON.parse(JSON.stringify(blocks[parentIndex][side][colIndex]));
        blocks[parentIndex][side].splice(colIndex + 1, 0, clone);
        markDirty();
        renderAllBlocks();
    }

    function addNestedBlock(parentIndex, side, type) {
        pushUndo();
        blocks[parentIndex][side].push(makeDefaultBlock(type));
        markDirty();
        renderAllBlocks();
    }

    /** A.2.5 / A.6.9 — move a nested block to the other column in the same Columns block. */
    function moveNestedBlock(parentIndex, fromSide, toSide, colIndex) {
        pushUndo();
        var item = blocks[parentIndex][fromSide].splice(colIndex, 1)[0];
        blocks[parentIndex][toSide].push(item);
        markDirty();
        renderAllBlocks();
    }

    // =========================================================================
    // Mini palette popup (Spec §2.1)
    // =========================================================================

    // Single-open invariant — close any existing popup before showing a new one.
    var $miniPaletteOpen = null;

    function showMiniPalette($button, parentIndex, side) {
        closeMiniPalette();
        var $popup = $(
            '<div class="hl-eb-mini-palette" role="menu" aria-label="Add nested block">' +
                '<button type="button" class="hl-eb-mini-palette-btn" data-type="text">Text</button>' +
                '<button type="button" class="hl-eb-mini-palette-btn" data-type="image">Image</button>' +
                '<button type="button" class="hl-eb-mini-palette-btn" data-type="button">Button</button>' +
                '<button type="button" class="hl-eb-mini-palette-btn" data-type="divider">Divider</button>' +
                '<button type="button" class="hl-eb-mini-palette-btn" data-type="spacer">Spacer</button>' +
                '<button type="button" class="hl-eb-mini-palette-btn hl-eb-mini-palette-disabled" disabled' +
                    ' title="Columns cannot be nested — email clients don\'t render nested tables reliably.">Columns</button>' +
            '</div>'
        );
        $button.after($popup);
        $miniPaletteOpen = $popup;

        $popup.find('.hl-eb-mini-palette-btn:not([disabled])').on('click', function () {
            var type = $(this).data('type');
            closeMiniPalette();
            addNestedBlock(parentIndex, side, type);
        });
    }

    function closeMiniPalette() {
        if ($miniPaletteOpen) {
            $miniPaletteOpen.remove();
            $miniPaletteOpen = null;
        }
    }
```

- [ ] **Step D.2.3: Wire the nested-block event delegation**

Inside `bindEvents()`, add these handlers (use delegation so they survive re-renders):

```javascript
        // Nested block delete.
        $(document).on('click', '.hl-eb-nested-del', function (e) {
            e.stopPropagation();
            var c = nestedCoords($(this));
            deleteNestedBlock(c.parent, c.side, c.colIndex);
        });

        // Nested block duplicate.
        $(document).on('click', '.hl-eb-nested-dup', function (e) {
            e.stopPropagation();
            var c = nestedCoords($(this));
            duplicateNestedBlock(c.parent, c.side, c.colIndex);
        });

        // Nested block move-to-other-column.
        $(document).on('click', '.hl-eb-nested-move', function (e) {
            e.stopPropagation();
            var c = nestedCoords($(this));
            var toSide = c.side === 'left' ? 'right' : 'left';
            moveNestedBlock(c.parent, c.side, toSide, c.colIndex);
        });

        // "+ Add Block" inside a column → show mini palette.
        $(document).on('click', '.hl-eb-col-add-btn', function (e) {
            e.stopPropagation();
            var $col = $(this).closest('.hl-eb-col');
            var parentIndex = parseInt($col.data('parent'), 10);
            var side = $col.data('side');
            showMiniPalette($(this), parentIndex, side);
        });

        // Click outside the mini palette closes it.
        $(document).on('click', function (e) {
            if (!$miniPaletteOpen) return;
            var $t = $(e.target);
            if ($t.closest('.hl-eb-mini-palette').length) return;
            if ($t.hasClass('hl-eb-col-add-btn')) return;
            closeMiniPalette();
        });

        // Escape closes the mini palette.
        $(document).on('keydown', function (e) {
            if (e.which === 27 && $miniPaletteOpen) {
                closeMiniPalette();
                e.stopPropagation();
            }
        });
```

- [ ] **Step D.2.4: Guard against nested-block delete/dup handlers from Task A's top-level delete**

The existing top-level handlers use `.hl-eb-block-del` and `.hl-eb-block-dup`. Our nested handlers use `.hl-eb-nested-del` / `.hl-eb-nested-dup` — distinct classes. But a nested block card has class `hl-eb-block hl-eb-block-nested`, so `$(this).closest('.hl-eb-block')` in the top-level handler would land on the nested card. Guard each top-level handler:

Find:

```javascript
        // Delete block.
        $(document).on('click', '.hl-eb-block-del', function () {
            var idx = $(this).closest('.hl-eb-block').data('index');
            blocks.splice(idx, 1);
            renderAllBlocks();
            markDirty();
        });
```

Already starts with `pushUndo();` from Task C. Replace with:

```javascript
        // Delete top-level block. Nested blocks have their own .hl-eb-nested-del class.
        $(document).on('click', '.hl-eb-block-del', function (e) {
            var $card = $(this).closest('.hl-eb-block');
            if ($card.hasClass('hl-eb-block-nested')) return; // Defensive — nested uses a different class.
            pushUndo();
            var idx = $card.data('index');
            blocks.splice(idx, 1);
            renderAllBlocks();
            markDirty();
        });
```

Same guard on `.hl-eb-block-dup`:

```javascript
        $(document).on('click', '.hl-eb-block-dup', function (e) {
            var $card = $(this).closest('.hl-eb-block');
            if ($card.hasClass('hl-eb-block-nested')) return;
            pushUndo();
            var idx = $card.data('index');
            var clone = JSON.parse(JSON.stringify(blocks[idx]));
            blocks.splice(idx + 1, 0, clone);
            renderAllBlocks();
            markDirty();
        });
```

### D.3 Canvas scroll + focus preservation across renderAllBlocks (A.2.1)

- [ ] **Step D.3.1: Preserve canvas scrollTop and active-editor focus across re-renders**

Replace `function renderAllBlocks()` with:

```javascript
    function renderAllBlocks() {
        destroyAllSortables();
        closeMiniPalette();

        var $canvas = $('#hl-eb-blocks');
        var scrollTop = $canvas.scrollTop();

        // Remember which contenteditable (if any) had focus so we can restore it after re-render.
        var $focused = $(document.activeElement);
        var focusIndex = null;
        var focusPath  = null; // for nested: [parent, side, colIndex]
        if ($focused.hasClass('hl-eb-text-editor') && !$focused.hasClass('hl-eb-text-editor-nested')) {
            var $card = $focused.closest('.hl-eb-block');
            focusIndex = parseInt($card.data('index'), 10);
        } else if ($focused.hasClass('hl-eb-text-editor-nested')) {
            var $n = $focused.closest('.hl-eb-block-nested');
            focusPath = [
                parseInt($n.data('parent'), 10),
                $n.data('side'),
                parseInt($n.data('col-index'), 10)
            ];
        }

        $canvas.empty();

        if (blocks.length === 0) {
            $canvas.html('<p style="color:#999;text-align:center;padding:40px;">Click "Add Block" to start building your email.</p>');
            return;
        }

        blocks.forEach(function (block, i) {
            $canvas.append(renderBlock(block, i));
        });

        initSortable();

        // Restore scroll + focus.
        $canvas.scrollTop(scrollTop);
        if (focusIndex !== null) {
            var $ed = $canvas.find('.hl-eb-block[data-index="' + focusIndex + '"]')
                             .find('.hl-eb-text-editor').first();
            if ($ed.length) { $ed.focus(); }
        } else if (focusPath) {
            var sel = '.hl-eb-block-nested[data-parent="' + focusPath[0] + '"]' +
                      '[data-side="' + focusPath[1] + '"]' +
                      '[data-col-index="' + focusPath[2] + '"]';
            var $ed2 = $canvas.find(sel).find('.hl-eb-text-editor-nested').first();
            if ($ed2.length) { $ed2.focus(); }
        }
    }
```

### D.4 CSS for columns editor

- [ ] **Step D.4.1: Append CSS**

Append below the Task C block in `assets/css/admin.css`:

```css
/* --- Columns block editor --------------------------------------- */
.hl-eb-block-columns .hl-eb-col-split {
    font-size: 12px;
    height: 26px;
    margin-right: 4px;
}

.hl-eb-col-body {
    padding: 8px;
    background: var(--eb-bg-subtle);
    border: 1px solid var(--eb-border);
    border-radius: var(--eb-radius-sm);
}

.hl-eb-col {
    background: #fff;
    border: 1px dashed var(--eb-border-med);
    border-radius: var(--eb-radius-sm);
    padding: 8px;
    min-height: 80px;
}

.hl-eb-col-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--eb-muted);
    margin-bottom: 6px;
}

.hl-eb-col-blocks {
    min-height: 40px;
}

.hl-eb-col-blocks:empty::before {
    content: "(empty)";
    display: block;
    padding: 16px 0;
    text-align: center;
    color: var(--eb-light);
    font-size: 12px;
    font-style: italic;
}

.hl-eb-col-add-wrap {
    margin-top: 8px;
    text-align: center;
    position: relative;
}

.hl-eb-block-nested {
    margin: 4px 0;
    background: #fff;
    border: 1px solid var(--eb-border);
    border-radius: 4px;
    padding: 6px;
    font-size: 12px;
}

.hl-eb-block-toolbar-nested {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 4px;
    font-size: 11px;
}

.hl-eb-block-type-nested {
    font-size: 11px;
    text-transform: uppercase;
    color: var(--eb-muted);
    cursor: grab;
    padding: 2px 6px;
    background: var(--eb-bg-subtle);
    border-radius: 3px;
}

.hl-eb-block-type-nested:active {
    cursor: grabbing;
}

.hl-eb-text-editor-nested {
    min-height: 40px;
    padding: 4px 6px;
    font-size: 13px;
    border: 1px solid var(--eb-border);
    border-radius: 3px;
}

/* --- Mini palette popup ----------------------------------------- */
.hl-eb-mini-palette {
    position: absolute;
    z-index: 50;
    top: calc(100% + 4px);
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    gap: 2px;
    padding: 4px;
    background: #fff;
    border: 1px solid var(--eb-border);
    border-radius: var(--eb-radius-sm);
    box-shadow: var(--eb-shadow-md);
    white-space: nowrap;
}

.hl-eb-mini-palette-btn {
    height: 28px;
    padding: 0 8px;
    font-size: 12px;
    background: #fff;
    border: 1px solid var(--eb-border);
    border-radius: 3px;
    cursor: pointer;
    color: var(--eb-text);
}

.hl-eb-mini-palette-btn:hover:not([disabled]) {
    background: var(--eb-bg-subtle);
    border-color: var(--eb-accent);
}

.hl-eb-mini-palette-btn.hl-eb-mini-palette-disabled {
    opacity: 0.45;
    cursor: not-allowed;
    background: #f9f9f9;
}
```

### D.5 Smoke + commit

- [ ] **Step D.5.1: Manual smoke test**

Deploy. Expected checks:
1. Add a top-level Columns block. Two empty column cards appear with "+ Add Block" buttons.
2. Click "+ Add Block" on the left. Mini palette appears with 5 enabled buttons + disabled "Columns" button (hover shows tooltip).
3. Click "Text" in the palette. A nested text block appears in the left column. Mini palette closes.
4. Click "+ Add Block" on the right. Select Image, Button, Divider, Spacer. Each appears.
5. Drag-reorder nested blocks within a column — works. Dragging a nested block into the other column does NOT work (pull:false/put:false).
6. Click the move-to-other-column button on a nested block → block jumps to the other column. Undo reverts.
7. Change split dropdown to 33/67 — column widths update visually; 33%/67% labels update.
8. Delete a nested block — undo restores.
9. Save template, reload — blocks_json round-trips correctly with nested arrays intact.
10. Test Ctrl+Z on a nested-block mutation (add, delete, dup, split, move) — each reverts.
11. Text block inside column: type, blur, Ctrl+Z — nested text reverts.

- [ ] **Step D.5.2: Confirm tests still pass**

```bash
wp hl-core test-email-renderer
```

Expected: ALL renderer assertions PASS.

- [ ] **Step D.5.3: Commit Task D**

```bash
git add includes/services/class-hl-email-block-renderer.php \
        assets/js/admin/email-builder.js \
        assets/css/admin.css
git commit -m "feat(email): columns block editing with nested blocks (Track 2.1)

- 5 split options: 50/50, 60/40, 40/60, 33/67, 67/33
- Per-column Sortable with distinct group (A.1.1 collision fix)
- Nested block rendering: text, image, button, divider, spacer
- Mini palette popup with disabled Columns + tooltip (A.7.9)
- Move-to-other-column button (A.2.5), pushes undo (A.6.9)
- Canvas scroll + editor focus preserved across renderAllBlocks (A.2.1)
- Renderer: get_column_widths() helper"
```

---

## Task E — Preview Modal (Spec §2.3)

**Files:**
- Modify: `includes/admin/class-hl-admin-email-builder.php` (modal HTML + POST preview endpoint + CSP + dark param)
- Modify: `assets/js/admin/email-builder.js` (open/close modal, srcdoc, device toggles, focus trap, search, skeleton)
- Modify: `assets/css/admin.css` (modal overlay + loading skeleton)

**Appendix items:** A.1.3 (sandbox allow-same-origin allow-popups), A.2.2 (srcdoc, not Blob), A.2.3 (Dark Backdrop rename + color-scheme meta), A.3.17 (Escape precedence: close search dropdown first), A.6.5 (CSP header), A.6.15 (loading skeleton), A.1.4 (mb_encode_mimeheader for subject in preview — apply on output path).

### E.1 PHP: accept POST, add dark param, CSP header

- [ ] **Step E.1.1: Rewrite `ajax_preview_render()` to accept POST + dark + CSP**

Open `includes/admin/class-hl-admin-email-builder.php`. Replace the entire `ajax_preview_render()` method with:

```php
    public function ajax_preview_render() {
        check_ajax_referer( 'hl_email_preview', '_wpnonce' );
        if ( ! current_user_can( 'manage_hl_core' ) ) {
            wp_die( 'Unauthorized' );
        }

        global $wpdb;

        // Accept both GET (legacy sidebar) and POST (v2 modal — avoids URL length limits).
        $source = ! empty( $_POST ) ? $_POST : $_GET;

        $template_id   = (int) ( $source['template_id'] ?? 0 );
        $enrollment_id = (int) ( $source['enrollment_id'] ?? 0 );
        $blocks_json   = wp_unslash( $source['blocks_json'] ?? '[]' );
        $subject       = sanitize_text_field( $source['subject'] ?? '' );
        $dark          = ! empty( $source['dark'] );

        // A.1.4 — Unicode-safe subject for preview title. Non-ASCII would otherwise render
        // as mojibake in the iframe <title>.
        if ( function_exists( 'mb_encode_mimeheader' ) && preg_match( '/[^\x20-\x7E]/', $subject ) ) {
            $subject_title = mb_encode_mimeheader( $subject, 'UTF-8', 'B' );
        } else {
            $subject_title = $subject;
        }

        $blocks = json_decode( $blocks_json, true );
        if ( ! is_array( $blocks ) ) {
            $blocks = array();
        }
        $blocks = $this->sanitize_blocks( $blocks );

        // Build merge tag context from enrollment (same logic as before).
        $context = array();
        if ( $enrollment_id ) {
            $enrollment = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id = %d",
                $enrollment_id
            ) );
            if ( $enrollment ) {
                $context['user_id']         = (int) $enrollment->user_id;
                $context['cycle_id']        = (int) $enrollment->cycle_id;
                $context['enrollment_id']   = (int) $enrollment->enrollment_id;
                $context['enrollment_role'] = $enrollment->roles ?? '';

                $user = get_userdata( (int) $enrollment->user_id );
                if ( $user ) {
                    $context['recipient_user_id'] = (int) $user->ID;
                    $context['recipient_name']    = $user->display_name;
                    $context['recipient_email']   = $user->user_email;
                }

                $cycle = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}hl_cycle WHERE cycle_id = %d",
                    $enrollment->cycle_id
                ) );
                if ( $cycle ) {
                    $context['cycle_name'] = $cycle->cycle_name;
                }
            }
        }

        $registry   = HL_Email_Merge_Tag_Registry::instance();
        $merge_tags = $registry->resolve_all( $context );
        $renderer   = HL_Email_Block_Renderer::instance();
        $html       = $renderer->render( $blocks, $subject_title, $merge_tags );

        // A.2.3 — Dark Backdrop: wrap rendered HTML's body content in a dark container,
        // and inject <meta name="color-scheme" content="dark"> so clients that honor it
        // also render in dark mode.
        if ( $dark ) {
            $html = preg_replace(
                '#<body([^>]*)>#i',
                '<body$1><meta name="color-scheme" content="dark"><div style="background-color:#1a1a2e;color:#e0e0e0;padding:20px;">',
                $html,
                1
            );
            $html = preg_replace( '#</body>#i', '</div></body>', $html, 1 );
        }

        // A.6.5 — CSP + security headers for the preview iframe.
        header( 'Content-Type: text/html; charset=utf-8' );
        header( "Content-Security-Policy: default-src 'none'; img-src https: data:; style-src 'unsafe-inline'; font-src https: data:" );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'X-Frame-Options: SAMEORIGIN' );

        echo $html;
        exit;
    }
```

### E.2 PHP: modal markup

- [ ] **Step E.2.1: Add modal skeleton to `render()`**

Open `includes/admin/class-hl-admin-email-builder.php`. Find the closing `</div>` of `<div class="wrap hl-email-builder-wrap">` (right before the `<script>` tag that emits `hlEmailBuilder`). Insert the modal markup before that closing `</div>`:

```php

            <!-- A.2 / A.6 — Preview modal (hidden until Preview button clicked) -->
            <div class="hl-eb-modal-overlay" id="hl-eb-modal" style="display:none;"
                 role="dialog" aria-modal="true" aria-labelledby="hl-eb-modal-title" aria-hidden="true">
                <div class="hl-eb-modal-header">
                    <div class="hl-eb-modal-title-wrap">
                        <strong class="hl-eb-modal-title" id="hl-eb-modal-title"><?php esc_html_e( 'Preview', 'hl-core' ); ?></strong>
                        <span class="hl-eb-modal-subtitle" id="hl-eb-modal-subtitle"></span>
                    </div>
                    <div class="hl-eb-modal-controls">
                        <div class="hl-eb-modal-devices" role="group" aria-label="<?php esc_attr_e( 'Device preview', 'hl-core' ); ?>">
                            <button type="button" class="hl-eb-modal-device active" data-mode="desktop"><?php esc_html_e( 'Desktop', 'hl-core' ); ?></button>
                            <button type="button" class="hl-eb-modal-device"         data-mode="mobile"><?php esc_html_e( 'Mobile', 'hl-core' ); ?></button>
                            <button type="button" class="hl-eb-modal-device"         data-mode="dark"><?php esc_html_e( 'Dark Backdrop', 'hl-core' ); ?></button>
                        </div>
                        <div class="hl-eb-modal-search">
                            <input type="text" id="hl-eb-modal-enrollment-search"
                                placeholder="<?php esc_attr_e( 'Search enrollments...', 'hl-core' ); ?>"
                                aria-label="<?php esc_attr_e( 'Search enrollments for preview context', 'hl-core' ); ?>">
                            <ul class="hl-eb-modal-search-results" id="hl-eb-modal-search-results" style="display:none;" role="listbox"></ul>
                        </div>
                        <button type="button" class="hl-eb-modal-close" id="hl-eb-modal-close"
                            aria-label="<?php esc_attr_e( 'Close preview', 'hl-core' ); ?>">&times;</button>
                    </div>
                </div>
                <div class="hl-eb-modal-body">
                    <div class="hl-eb-modal-skeleton" id="hl-eb-modal-skeleton">
                        <div class="hl-eb-skeleton-line" style="width:60%;"></div>
                        <div class="hl-eb-skeleton-line" style="width:90%;"></div>
                        <div class="hl-eb-skeleton-line" style="width:75%;"></div>
                        <div class="hl-eb-skeleton-line" style="width:50%;"></div>
                    </div>
                    <iframe id="hl-eb-modal-iframe" title="<?php esc_attr_e( 'Email preview', 'hl-core' ); ?>"
                            sandbox="allow-same-origin allow-popups"></iframe>
                </div>
            </div>
```

### E.3 JS: modal lifecycle + device toggles + srcdoc + focus trap

- [ ] **Step E.3.1: Add modal module to `email-builder.js`**

Near the bottom of the IIFE (above `escHtml`), add:

```javascript
    // =========================================================================
    // Preview modal (Spec §2.3)
    // =========================================================================

    var modalState = {
        open: false,
        mode: 'desktop',       // desktop | mobile | dark
        enrollmentId: 0,
        lastFocusEl: null,
        searchDropdownOpen: false
    };

    function openPreviewModal() {
        var $modal = $('#hl-eb-modal');
        if (!$modal.length) return;

        modalState.lastFocusEl = document.activeElement;
        modalState.open = true;
        modalState.mode = 'desktop';
        // Inherit the sidebar's current enrollment selection if set.
        var existing = parseInt($('#hl-eb-preview-enrollment').val(), 10);
        modalState.enrollmentId = isNaN(existing) ? 0 : existing;

        $modal.find('.hl-eb-modal-device').removeClass('active')
              .filter('[data-mode="desktop"]').addClass('active');
        $modal.find('#hl-eb-modal-title').text($('#hl-eb-name').val() || 'Preview');
        $modal.find('#hl-eb-modal-subtitle').text('');

        // Set width per mode before loading.
        $('#hl-eb-modal-iframe').css('width', '600px');

        // Fade in (200ms).
        $modal.attr('aria-hidden', 'false').css('display', 'flex').hide().fadeIn(200);
        $('body').css('overflow', 'hidden');

        // Load initial preview.
        refreshModalPreview();

        // Initial focus → close button.
        setTimeout(function () { $('#hl-eb-modal-close').focus(); }, 220);
    }

    function closePreviewModal() {
        var $modal = $('#hl-eb-modal');
        if (!modalState.open) return;
        modalState.open = false;
        modalState.searchDropdownOpen = false;
        $('#hl-eb-modal-search-results').hide().empty();
        $modal.fadeOut(200, function () {
            $modal.attr('aria-hidden', 'true').css('display', 'none');
            // Clear iframe to free memory.
            $('#hl-eb-modal-iframe').removeAttr('srcdoc').attr('srcdoc', '');
        });
        $('body').css('overflow', '');
        if (modalState.lastFocusEl) {
            try { modalState.lastFocusEl.focus(); } catch (e) {}
        }
    }

    function refreshModalPreview() {
        var $iframe = $('#hl-eb-modal-iframe');
        var $skel   = $('#hl-eb-modal-skeleton');

        $skel.show();
        $iframe.css('visibility', 'hidden');

        var params = new URLSearchParams();
        params.set('action',       'hl_email_preview_render');
        params.set('_wpnonce',     config.previewNonce || '');
        params.set('template_id',  String(config.templateId || 0));
        params.set('enrollment_id', String(modalState.enrollmentId || 0));
        params.set('subject',      $('#hl-eb-subject').val() || '');
        params.set('blocks_json',  JSON.stringify(blocks));
        if (modalState.mode === 'dark') {
            params.set('dark', '1');
        }

        // A.2.2 — use srcdoc instead of Blob URL. POST the payload, receive HTML, inject as srcdoc.
        fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString()
        }).then(function (r) { return r.text(); }).then(function (html) {
            $iframe.one('load', function () {
                $skel.hide();
                $iframe.css('visibility', 'visible');
            });
            // Assign via DOM for srcdoc (jQuery .attr with srcdoc triggers correctly in modern browsers).
            $iframe.get(0).srcdoc = html;
        }).catch(function () {
            $skel.hide();
            $iframe.css('visibility', 'visible');
            $iframe.get(0).srcdoc = '<html><body style="font-family:sans-serif;padding:40px;color:#c00;">Preview failed to load. Check console.</body></html>';
        });
    }

    function setModalDevice(mode) {
        modalState.mode = mode;
        $('.hl-eb-modal-device').removeClass('active').filter('[data-mode="' + mode + '"]').addClass('active');
        var w = (mode === 'mobile') ? '375px' : '600px';
        $('#hl-eb-modal-iframe').css('width', w);
        refreshModalPreview();
    }

    // Focus trap: keep Tab / Shift+Tab cycling within the modal's focusable children.
    function trapModalTab(e) {
        if (!modalState.open || e.which !== 9) return;
        var $focusables = $('#hl-eb-modal').find('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])').filter(':visible:not([disabled])');
        if (!$focusables.length) return;
        var first = $focusables.get(0);
        var last  = $focusables.get($focusables.length - 1);
        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    }
```

- [ ] **Step E.3.2: Expose the preview nonce to JS config**

Open `class-hl-admin-email-builder.php`. Find the inline `<script>` that emits `hlEmailBuilder`. Add one field — `previewNonce` — right after `nonce:`:

Current:

```php
                nonce: <?php echo wp_json_encode( wp_create_nonce( 'hl_email_builder' ) ); ?>,
```

Insert after:

```php
                previewNonce: <?php echo wp_json_encode( wp_create_nonce( 'hl_email_preview' ) ); ?>,
```

- [ ] **Step E.3.3: Bind modal events**

In `bindEvents()` in email-builder.js, append:

```javascript
        // Preview modal open / close.
        $('#hl-eb-preview-btn').on('click', openPreviewModal);
        $('#hl-eb-modal-close').on('click', closePreviewModal);
        $(document).on('click', '.hl-eb-modal-overlay', function (e) {
            // Backdrop click (but not clicks inside the header or body) closes the modal.
            if (e.target === this) closePreviewModal();
        });

        // Device toggles.
        $(document).on('click', '.hl-eb-modal-device', function () {
            setModalDevice($(this).data('mode'));
        });

        // Modal enrollment search.
        var modalSearchTimer = null;
        $('#hl-eb-modal-enrollment-search').on('input', function () {
            clearTimeout(modalSearchTimer);
            var q = $(this).val();
            modalSearchTimer = setTimeout(function () { modalSearchEnrollments(q); }, 300);
        });
        $(document).on('click', '.hl-eb-modal-search-result', function () {
            modalState.enrollmentId = parseInt($(this).data('enrollment-id'), 10) || 0;
            $('#hl-eb-modal-enrollment-search').val($(this).text());
            $('#hl-eb-modal-search-results').hide();
            modalState.searchDropdownOpen = false;
            refreshModalPreview();
        });

        // Focus trap inside modal.
        $(document).on('keydown', trapModalTab);

        // A.3.17 — Escape precedence: close search dropdown first, then modal.
        $(document).on('keydown', function (e) {
            if (e.which !== 27) return; // Esc
            if (!modalState.open) return;
            if (modalState.searchDropdownOpen) {
                $('#hl-eb-modal-search-results').hide();
                modalState.searchDropdownOpen = false;
                e.stopPropagation();
                return;
            }
            closePreviewModal();
            e.stopPropagation();
        });
```

- [ ] **Step E.3.4: Add `modalSearchEnrollments` helper**

Just above `searchEnrollments` in email-builder.js, add:

```javascript
    function modalSearchEnrollments(query) {
        if ((query || '').length < 2) {
            $('#hl-eb-modal-search-results').hide().empty();
            modalState.searchDropdownOpen = false;
            return;
        }
        $.post(config.ajaxUrl, {
            action: 'hl_email_preview_search',
            nonce: config.nonce,
            search: query
        }, function (res) {
            var $list = $('#hl-eb-modal-search-results').empty();
            if (!res.success || !res.data.length) {
                $list.append('<li class="hl-eb-modal-search-empty">No matches.</li>').show();
                modalState.searchDropdownOpen = true;
                return;
            }
            res.data.forEach(function (item) {
                $list.append(
                    '<li class="hl-eb-modal-search-result" role="option"' +
                    ' data-enrollment-id="' + item.enrollment_id + '">' +
                    escHtml(item.label) + '</li>'
                );
            });
            $list.show();
            modalState.searchDropdownOpen = true;
        });
    }
```

### E.4 CSS: modal + skeleton

- [ ] **Step E.4.1: Append modal CSS**

Append below the Task D block in `assets/css/admin.css`:

```css
/* --- Preview modal ---------------------------------------------- */
.hl-eb-modal-overlay {
    position: fixed;
    inset: 0;
    z-index: 100000;
    display: flex;
    flex-direction: column;
    background: rgba(0, 0, 0, 0.75);
}

.hl-eb-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    height: 56px;
    padding: 0 16px;
    background: #1A2B47;
    color: #fff;
    flex-shrink: 0;
}

.hl-eb-modal-title {
    font-size: 15px;
    font-weight: 700;
    color: #fff;
}

.hl-eb-modal-subtitle {
    margin-left: 10px;
    color: #9CA3AF;
    font-size: 13px;
}

.hl-eb-modal-controls {
    display: flex;
    gap: 12px;
    align-items: center;
}

.hl-eb-modal-devices {
    display: inline-flex;
    border: 1px solid #2d3e5e;
    border-radius: 4px;
    overflow: hidden;
}

.hl-eb-modal-device {
    height: 32px;
    padding: 0 12px;
    background: transparent;
    color: #cbd5e1;
    border: 0;
    border-right: 1px solid #2d3e5e;
    cursor: pointer;
    font-size: 12px;
}

.hl-eb-modal-device:last-child { border-right: 0; }

.hl-eb-modal-device.active {
    background: #2C7BE5;
    color: #fff;
}

.hl-eb-modal-search {
    position: relative;
}

.hl-eb-modal-search input {
    height: 32px;
    background: #0f1e38;
    color: #fff;
    border: 1px solid #2d3e5e;
    border-radius: 4px;
    padding: 0 10px;
    font-size: 13px;
    width: 220px;
}

.hl-eb-modal-search-results {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    max-height: 260px;
    overflow-y: auto;
    margin: 4px 0 0;
    padding: 4px 0;
    list-style: none;
    background: #0f1e38;
    border: 1px solid #2d3e5e;
    border-radius: 4px;
    z-index: 1;
}

.hl-eb-modal-search-result,
.hl-eb-modal-search-empty {
    padding: 8px 12px;
    font-size: 13px;
    color: #cbd5e1;
    cursor: pointer;
}

.hl-eb-modal-search-result:hover {
    background: #1A2B47;
    color: #fff;
}

.hl-eb-modal-close {
    width: 36px;
    height: 36px;
    background: transparent;
    color: #fff;
    border: 0;
    font-size: 24px;
    cursor: pointer;
}

.hl-eb-modal-close:hover {
    background: rgba(255, 255, 255, 0.08);
}

.hl-eb-modal-body {
    flex: 1;
    display: flex;
    justify-content: center;
    align-items: flex-start;
    padding: 24px;
    background: #111827;
    overflow: auto;
    position: relative;
}

.hl-eb-modal-body iframe {
    height: calc(100vh - 120px);
    max-height: 900px;
    width: 600px;
    background: #fff;
    border: 0;
    border-radius: 8px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
}

/* --- Modal loading skeleton ------------------------------------- */
.hl-eb-modal-skeleton {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 520px;
    max-width: 80%;
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.hl-eb-skeleton-line {
    height: 14px;
    background: linear-gradient(90deg, #1f2937 0%, #374151 50%, #1f2937 100%);
    background-size: 200% 100%;
    border-radius: 3px;
    animation: hlEbSkeletonShimmer 1.4s linear infinite;
}

@keyframes hlEbSkeletonShimmer {
    0%   { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}
```

### E.5 Smoke + commit

- [ ] **Step E.5.1: Manual smoke**

Deploy. Open the builder with an existing template containing a text block, a button, and a columns block.
1. Click Preview button (top toolbar). Modal fades in, dark header, iframe loads with skeleton → skeleton fades → rendered email appears.
2. Click Mobile → iframe narrows to 375px.
3. Click Dark Backdrop → iframe re-renders with dark wrapper around the email body.
4. Type in the search box "test" — debounce 300ms → AJAX fires → dropdown results list. Click a result → iframe reloads with that enrollment's merge-tag context (e.g., `{{recipient_name}}` resolves).
5. Press Escape while search results visible — search closes. Press Escape again — modal closes.
6. Press Escape with no dropdown → modal closes immediately.
7. Click the backdrop (outside the header and iframe container) — modal closes.
8. Click × — modal closes.
9. Tab focus trap: inside modal, repeated Tab cycles through device buttons + search + close; never escapes to the page underneath.
10. Open DevTools network tab → verify the preview POST request carries `blocks_json` in the body, not the URL. No Blob URL created. Response includes the `Content-Security-Policy` header.
11. Verify iframe sandbox attribute is `allow-same-origin allow-popups` (no `allow-scripts`). Inline script inside the email HTML would not execute (defense in depth).
12. Open browser memory monitor — repeated open/close/mode toggle does NOT grow retained iframe memory (srcdoc, not blob URL).

- [ ] **Step E.5.2: Run the renderer tests one more time**

```bash
wp hl-core test-email-renderer
```

Expected: all 12 PASS.

- [ ] **Step E.5.3: Commit Task E**

```bash
git add includes/admin/class-hl-admin-email-builder.php \
        assets/js/admin/email-builder.js \
        assets/css/admin.css
git commit -m "feat(email): fullscreen preview modal (Track 2.3)

- Fullscreen overlay with dark header, 56px height
- Desktop / Mobile / Dark Backdrop device toggles (A.2.3 rename)
- Enrollment search with 300ms debounce
- iframe.srcdoc not Blob URL (A.2.2 memory leak fix)
- sandbox=allow-same-origin allow-popups only (A.1.3)
- CSP header on preview endpoint (A.6.5)
- Loading skeleton with shimmer animation (A.6.15)
- Focus trap + restore-focus-on-close
- Escape precedence: search dropdown first, then modal (A.3.17)
- POST preview endpoint avoids URL length limits
- mb_encode_mimeheader for non-ASCII subject (A.1.4)"
```

---

## Final Verification

### F.1 Regression sweep

- [ ] **Step F.1.1: Run the full renderer harness**

```bash
wp hl-core test-email-renderer
```

Expected: **12 passed, 0 failures.**

- [ ] **Step F.1.2: Smoke test existing v1 templates**

On the test server:

```bash
wp db query "SELECT template_id, template_key, status FROM wp_hl_email_template ORDER BY template_id ASC LIMIT 10"
```

For each of 3 representative templates, open in the builder. Confirm:
- Blocks render with no console errors.
- Save + reload round-trip produces identical `blocks_json`.
- Old templates without `text_align` / `font_size` render text blocks identically to how they did before (default styles).
- Old columns with only 50/50 or 60/40 splits render and save correctly.

- [ ] **Step F.1.3: Existing smoke test suite**

```bash
wp hl-core smoke-test
```

Expected: no new failures compared to the pre-Track-2 baseline.

- [ ] **Step F.1.4: Send a real test email end-to-end**

Pick one test template, open in builder, tweak a text block (center align, size 18), preview in modal (Desktop, Mobile, Dark Backdrop), save, trigger a manual send to your own email. Open in Gmail — alignment and size correct. Open in Outlook web — Inner `<span>` carries the font size; alignment correct.

### F.2 Documentation

- [ ] **Step F.2.1: Update STATUS.md**

Open `STATUS.md`. Check off the Track 2 build queue items:
- `[x] 2.4 Text Block Alignment + Font Size`
- `[x] 2.2 Undo / Redo`
- `[x] 2.1 Columns Block Editing`
- `[x] 2.3 Preview Modal`

Add a brief "Last completed" note.

- [ ] **Step F.2.2: Update README.md**

Open `README.md`. Under "What's Implemented" → Email System, add:

```
- Email Builder v2 (Track 2):
  - Columns block editing with 5 splits, nested blocks, per-column Sortable
  - Undo / Redo with 50-snapshot ring buffer (Ctrl+Z / Ctrl+Y)
  - Fullscreen preview modal with device toggles and enrollment search
  - Text block alignment + font size (6 presets)
```

Add `tests/test-email-block-renderer.php` and `includes/cli/class-hl-cli-test-email-renderer.php` to the file tree section.

- [ ] **Step F.2.3: Commit docs**

```bash
git add STATUS.md README.md
git commit -m "docs: mark Email v2 Track 2 (builder) complete"
```

- [ ] **Step F.2.4: Push to remote**

```bash
git push origin main
```

---

## Self-Review Checklist

Run through this after completing all tasks. Do NOT dispatch a subagent — fix issues inline.

**Spec coverage:**
- [ ] §2.1 Columns: 5 splits ✓ (Task D.1), nested edit ✓ (Task D.2), mini palette ✓ (Task D.2), per-column Sortable with distinct group ✓ (Task D.2), move-to-other-column ✓ (Task D.2), disabled Columns in palette ✓ (Task D.2 A.7.9).
- [ ] §2.2 Undo/Redo: 50-snapshot ring buffer ✓ (Task C.2.1), Ctrl+Z/Y ✓ (Task C.2.5), text debounce ✓ (Task C.2.2), flush on undo ✓ (Task C.2.1).
- [ ] §2.3 Preview modal: fullscreen overlay ✓ (Task E.2), Desktop/Mobile/Dark toggles ✓ (Task E.2/E.3), enrollment search ✓ (Task E.3.4), srcdoc ✓ (Task E.3.1), sandbox ✓ (Task E.2.1), CSP ✓ (Task E.1.1), skeleton ✓ (Task E.4), focus trap ✓ (Task E.3.1).
- [ ] §2.4 Text formatting: alignment radio group ✓ (Task B.2.1), font size dropdown ✓ (Task B.2.1), block-level via CSS (not execCommand) ✓ (Task B.2.1), renderer td + inner span ✓ (Task B.1.2).

**Appendix A coverage (Track 2 items):**
- [ ] A.1.1 Sortable collision — distinct handle class + group + filter + registry destroy ✓ (Task A.3, Task D.2.2)
- [ ] A.1.3 Preview sandbox without allow-scripts ✓ (Task E.2.1)
- [ ] A.1.4 mb_encode_mimeheader subject ✓ (Task E.1.1)
- [ ] A.2.1 Focus preservation + flush on undo + Safari beforeinput ✓ (Task C.2.2, Task D.3.1)
- [ ] A.2.2 srcdoc not Blob ✓ (Task E.3.1)
- [ ] A.2.3 Dark Backdrop rename + color-scheme meta ✓ (Task E.1.1, Task E.2.1)
- [ ] A.2.4 Autosave 5s debounce after undo ✓ (Task C.3.1)
- [ ] A.2.5 Move-to-other-column button + moveNestedBlock pushUndo ✓ (Task D.2.2)
- [ ] A.2.11 Text alignment default left active ✓ (Task B.2.1)
- [ ] A.3.1 Outlook inner <span> font-size ✓ (Task B.1.2)
- [ ] A.3.17 Escape closes dropdown then modal ✓ (Task E.3.3)
- [ ] A.6.5 CSP response header ✓ (Task E.1.1)
- [ ] A.6.9 moveNestedBlock pushUndo ✓ (Task D.2.2)
- [ ] A.6.15 Preview loading skeleton ✓ (Task E.3.1, Task E.4.1)
- [ ] A.6.16 Undo/redo tooltips + hint ✓ (Task C.1.1)
- [ ] A.6.17 Merge-tag CSS truncation (not char cap) — **note:** handled by renderer-side CSS in the email HTML. If v1 does not already emit `text-overflow:ellipsis` on merge-tag spans, it is out of Track 2 scope — leave as-is and file a follow-up issue.
- [ ] A.7.8 One-time undo-clear notice ✓ (Task C.1.2, C.3.2, C.3.3)
- [ ] A.7.9 Disabled Columns mini palette button with tooltip ✓ (Task D.2.2)
- [ ] A.7.14 Per user per template notice meta key ✓ (Task C.1.2, C.1.3)

**Placeholder scan:** None found — every code block is complete.

**Type / identifier consistency:**
- `pushUndo` / `undo` / `redo` / `updateUndoButtons` — consistent across Tasks A, C, D.
- `destroyAllSortables` — defined in A, used in A, C, D.
- `flushPendingTextSnapshot` — defined in C.2.1, used in C.2.2, C.2.5.
- `window._hlPendingTextSnap` — single global slot, written in B.2.1 (rewritten in C.2.2), read in C.2.1.
- `sortableRegistry` — initialized in A, pushed to in A/D, destroyed in A.
- `NestedBlockShim` class — defined in D.2.2, used in D.2.2 (same task).
- `getColumnWidthsJS` — JS mirror of PHP `get_column_widths()`, names different by design (JS suffix to avoid global name pollution).
- `moveNestedBlock` — defined in D.2.2, called in D.2.3.
- `modalState` object — defined in E.3.1, used throughout E.3.x.

**Cross-task dependencies verified:**
- Task A's stubbed `pushUndo` is safely callable by Task B's alignment handlers before Task C replaces it with the real implementation. ✓
- Task A's `sortableRegistry` is safely empty until Task D starts pushing nested Sortable instances into it. ✓
- Task C's undo-clear notice markup references `template_id`; Task E does not touch it. ✓
- Task E's Preview button stub is added in Task C.1.1 (toolbar rewrite) before the click handler lands in Task E.3.3. ✓

All checks pass. Plan is executable top to bottom.

---

## Rollback Plan

If any task breaks the builder in a way that cannot be fixed forward quickly:

```bash
# Identify the last good commit before the offending task.
git log --oneline -- assets/js/admin/email-builder.js includes/admin/class-hl-admin-email-builder.php includes/services/class-hl-email-block-renderer.php

# Revert a specific commit.
git revert <sha>

# Or for emergency, checkout the last-good state of one file:
git checkout <sha>^ -- assets/js/admin/email-builder.js
```

Deployment note: All changes land in existing files plus 3 new ones (test harness, CLI wrapper, fixtures JSON). Removing the new files is safe — no DB migration is involved.

---

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-04-11-email-v2-track2-builder.md`.

Two execution options:

**1. Subagent-Driven (recommended)** — Fresh subagent per task with review between tasks. Best for a plan this large (5 tasks, ~40 steps).

**2. Inline Execution** — Execute tasks in the current session via `superpowers:executing-plans`, batch execution with checkpoints.

Which approach?
