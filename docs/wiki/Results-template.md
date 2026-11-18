Written against v0.6.1.

How results are rendered while UAMSWP Search answers a search, and when the theme and the existing search plugins stay in control.

## The template

On the reference Genesis theme, the plugin supplies its own search template while its engine is selected, because the theme's template runs several secondary queries. The template renders one combined, paginated list with h1, h2 and h3 headings, visible scope and type links, safe excerpts, and the optional Google block. On other themes the plugin supplies a header, main and footer template. Either can be replaced with the `uams_search_template` filter; see [Filters and PHP API](Filters-and-PHP-API.md).

## Provider cards

Provider results use portrait cards matching the location-page Find-a-Doc layout: the name and degrees, the clinical title (or Resident Physician), and a **View Profile** link. Consecutive provider results share a responsive row of cards without changing relevance order or pagination. Other results keep their text excerpts.

## Location cards

Location results use the directory's landscape cards: linked titles and parents, the parent's address and photo inherited where the location has none, the child's own phone routing, directions, and closure or modified-hours notices. Hidden or private parent details are not inherited.

Card fields and images for both kinds are read live on the result's own sub-site, with safe fallbacks for a missing name or image. Updating from 0.3.0 to 0.4.0 or later does not need another rebuild for the cards.

## When the theme stays in control

- Outside an active search request, the theme is untouched.
- A missing index or a query error leaves the existing search running.
- A signed-in `engine=wordpress` request bypasses UAMSWP Search; a signed-in `engine=uams` request previews it while visitor search is off.
- Relevanssi and Ajax Search Pro yield only after UAMSWP Search has answered that query. They keep handling ordinary searches and fallback requests.

## Reference

- [Find-a-Doc content](Find-a-Doc-content.md)
- [Settings](Settings.md)
