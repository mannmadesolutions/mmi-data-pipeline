# Product Specs

*Added in 2.33.0 (display), 2.35.0 (editor)*

The **Product Specs** box on the product edit screen holds a product's structured content: system requirements per OS, platforms, licensing/authorization, features, curated top features, YouTube videos, a specifications table, and a disclaimer. Imports fill these fields automatically (from XChange Web Assets, for example); you can edit them here.

## Editing

- **System requirements:** one column each for macOS, Windows and Linux. RAM and disk are in GB. Leave a field blank to hide that row.
- **Features, top features, videos:** one entry per line. For videos, paste a YouTube URL or an 11-character video ID. The first video is the product's main video.
- **Specifications:** one per line, as `Label: Value`, for example `Delivery Format: Download`.
- **Disclaimer:** shown as a "Note:" banner on the product page.

**Your edits are protected from imports.** When you change a field that an import can also fill (requirements, features, videos, licensing, platforms), that field is added to the product's [Import Field Locks](field-locks.md) automatically, so the next feed refresh won't undo your change. Saving a product without changing a field doesn't lock it. To let imports manage a field again, uncheck it in the **Import Field Locks** box.

## Showing specs in Bricks

| Use | How |
|---|---|
| A list (one row per requirement, feature, video…) | Query loop → type **MMI Spec: …**, then `{mmi_spec_item}` inside the loop (`{mmi_spec_item_label}`, `{mmi_spec_item_text}` and `{mmi_spec_item_id}` for the parts, e.g. a YouTube ID for a Video element) |
| A single value | `{mmi_spec_disclaimer}`, `{mmi_spec_mpn}`, or any `{mmi_spec_<concept>}` (lists are joined with commas) |
| Show/hide a section | Condition: `{mmi_spec_<concept>_count}` **is not empty** |

Concepts: `requirements_mac`, `requirements_windows`, `requirements_linux`, `platforms`, `licensing`, `features`, `key_features` (first 4 features), `top_features`, `videos`, `video_primary`, `specs`, `disclaimer`, `mpn`.

## Quality score (with MMI Data Health)

When MMI Data Health is active, this plugin adds a **Product Quality** rule pack (Data Health → Rule Packs). Products are scored on:
- a rich description (200+ characters)
- a readable title (not ALL CAPS or a bare number)
- a title that starts with the brand
- "Download" in digital products' titles
- system requirements and 3+ features on software, with requirements that are structured rather than free text only
- a product identifier (GTIN, or brand + MPN, which is Google Merchant Center's rule)
- at least one gallery image

"Software" means products in the `software-pro-audio` category tree. The checks read the same data the product page shows.

## For developers

`MMI_Product_Content_Registry` is the single read/write model:

```php
MMI_Product_Content_Registry::get_items( $product_id, 'requirements_mac' ); // display rows {value,label,text,id}
MMI_Product_Content_Registry::source( $product_id, 'features' );            // which meta key supplied it
MMI_Product_Content_Registry::canonical_value( $product_id, 'specs' );      // storage-shaped value
MMI_Product_Content_Registry::store( $product_id, 'specs', [ [ 'label' => 'Format', 'value' => 'Download' ] ] );
```

Always write through `store()`. It normalizes the value and writes slashed JSON. A raw `update_post_meta()` with JSON strips the backslash from escapes like `’`, which corrupts the text ("Avidu2019s").
