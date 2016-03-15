<h1>{$results['title']}</h1>
{foreach item=item from=$results['items']}
{link href="NAVEGAR {$item['link']}" caption="{$item['title']}"}<br/>
<p align="justify"><i>{$item['pubDate']}</i> - {$item['description']}</p> 
{/foreach}