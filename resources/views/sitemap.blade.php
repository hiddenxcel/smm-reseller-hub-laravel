{{-- The angle bracket is built from a character code rather than typed.

     short_open_tag is on for this PHP build, so the lexer treats a literal
     opening bracket followed by a question mark as the start of PHP and tries
     to parse the rest as code — even inside a quoted string, because the
     string is only a string once the lexer has decided where the PHP begins.
     Splitting the sequence is what stops it being one. --}}
{!! chr(60).'?xml version="1.0" encoding="UTF-8"?'.chr(62) !!}
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
