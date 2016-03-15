<h1>Navegando en la web con Apretaste!</h1>
<table>
{foreach item=item from=$results}
<tr>
{link href="NAVEGAR {$item->url}" caption="{$item->title}"}<br/>
<small>{$item->domain}, <i>{$item->date|date_format:"%A, %B %e, %Y"}</i></small>
<p align="justify"> 
	{$item->kwic}</p>
{/foreach}
</td>
</table>