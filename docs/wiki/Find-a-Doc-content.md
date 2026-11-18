Written against v0.6.1.

Which Find-a-Doc fields and taxonomies are indexed by default, which visibility flags are honored, and how provider names are copied onto related pages. The defaults were taken from Find-a-Doc's saved field definitions. Only explicitly configured fields are read; add more with the `uams_search_fields` filter ([Filters and PHP API](Filters-and-PHP-API.md)).

## Default fields

| Post type | Fields | Taxonomies |
| --- | --- | --- |
| `provider` | `physician_first_name`, `physician_middle_name`, `physician_last_name`, `physician_full_name`, `physician_medium_name`, `physician_clinical_bio`, `physician_short_clinical_bio`, `physician_clinical_focus`, `physician_research_interests`, `physician_additional_info` | `condition`, `treatment`, `service_line`, `degree`, `language`, `clinical_title`, `affiliation` |
| `location` | `location_about`, `location_short_desc`, `location_description`, `location_address_1`, `location_address_2`, `location_city`, `location_state`, `location_zip`, `location_abbreviation`, `location_web_name`, `location_parking`, `location_direction` | `service_line`, `language`, `region`, `building`, `parking` |
| `expertise` | `post_excerpt`, `expertise_alternate_names` | none |
| `post` | none | `category`, `post_tag` |

Every default field and taxonomy has weight 1.

## Visibility flags

`physician_hidden`, an explicit `physician_searchable=0`, and `location_hidden` are respected both while indexing and while querying.

## Related provider names

The four saved provider lists are indexed automatically, so a search for a provider's name also finds the pages that list them:

| Field | On |
| --- | --- |
| `physician_locations` | locations |
| `physician_expertise` | expertise pages |
| `condition_physicians` | conditions |
| `treatment_procedure_physicians` | treatments |

- Only linked, searchable, public provider titles are copied, with a default weight of 0.25 (`related_provider_weight` in [Settings](Settings.md)). The provider's own profile normally ranks above a page that matches only through a related name.
- Relationships are not followed recursively.
- A configured relationship, post object, taxonomy or ACFE taxonomy terms field copies the titles of its searchable linked posts and the names of its linked terms the same way, with the field's own weight. The same fields inside a block or a repeater, and any other object ID, are not treated as names; an integration can resolve one through `uams_search_field_value`.

## Keeping copied names current

- Editing, hiding, trashing or deleting a linked post, or renaming or deleting a linked term, queues the pages that copied it. Until a page is refreshed, live query guards hide it, which protects excerpts as well as matching. It becomes searchable by its own text again after the refresh.
- A change that leaves a copied title or name as it was queues nothing, so two pages that link to each other settle.
- Provider edits queue dependent pages when the provider's own queue item is processed, so dependent pages may need the following batch.
- Hidden providers stay as internal dependencies, so publishing a provider can restore related matches.

**Note:** version 0.3.0 needed a full rebuild to add these names to existing rows. A change to which fields copy names flags existing indexes for a rebuild.

## Reference

- [Results template](Results-template.md)
- [Rebuilding the index](Rebuilding-the-index.md)
