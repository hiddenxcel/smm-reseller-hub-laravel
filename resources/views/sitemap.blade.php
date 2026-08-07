{{-- No leading whitespace: an XML declaration must be the first byte, and a
     stray newline makes the whole document invalid. --}}
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($entries as $entry)
    <url>
        <loc>{{ $entry['loc'] }}</loc>
@isset ($entry['lastmod'])
        <lastmod>{{ $entry['lastmod'] }}</lastmod>
@endisset
        <changefreq>{{ $entry['freq'] }}</changefreq>
        <priority>{{ $entry['priority'] }}</priority>
    </url>
@endforeach
</urlset>
