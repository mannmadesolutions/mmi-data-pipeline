# Field Locks

*Added in 2.32.0*

A **field lock** protects one field on one product from being overwritten by imports. Use it when you've hand-edited a product's title or description, or any other imported field, and want your version to survive every future feed refresh. Everything else on the product (price, stock, and so on) keeps updating normally.

## Locking fields

- **One product:** on the product edit screen, open the **Import Field Locks** box in the sidebar, check the fields to protect, and click **Update**. Title, Description and Short Description are listed first; everything else is under **More fields**.
- **Many products:** in **Products → All Products**, select products and choose a bulk action:
  - **Lock title from imports**
  - **Lock description from imports** (locks the description and short description)
  - **Unlock all import fields**

Every field the Field Mapping panel can import is lockable except the SKU and the computed Active Price, which the importer needs for matching and pricing.

## What a lock does

| | Locked field |
|---|---|
| Scheduled and manual imports (every Import Profile) | Never written on that product |
| Import Preview / Review & Compare | Shown as non-actionable, and not counted in "will update" |
| Taxonomies (categories, tags, brand, custom taxonomies) | Terms are not reassigned, including by Taxonomy Mapping aliases and the supplier's default Distribution term |
| Creating a new product | Not affected. A lock only exists on a product that already exists. |
| Editing the product yourself | Not affected. Locks only stop imports. |
| Duplicate Products → Apply field winners | Not affected. That's an explicit choice you make in the review screen. |

## For developers

Locks are stored in the `_mmi_locked_fields` postmeta as an array of Field Mapping keys (`post_title`, `post_content`, `_regular_price`, `product_brand`, …).

```php
MMI_Pipeline_Field_Locks::lock( $product_id, 'post_title' );
MMI_Pipeline_Field_Locks::unlock( $product_id, 'post_title' );
MMI_Pipeline_Field_Locks::is_locked( $product_id, 'post_title' ); // bool
MMI_Pipeline_Field_Locks::set( $product_id, [ 'post_title', 'post_content' ] ); // replace the whole list
MMI_Pipeline_Field_Locks::get( $product_id ); // string[]
```

- `tax:{slug}` field names and the dynamic importer's short names (`name`, `description`, `regular_price`, `images`, …) are accepted and normalized.
- Unknown keys are silently dropped.
- Reading a lock uses WordPress's post-meta cache, so checking it inside an import loop adds no queries.
