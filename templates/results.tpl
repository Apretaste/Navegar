<h1>Navegando en la web con Apretaste!</h1>
<table>
{foreach item=item from=$results}
<tr>
{link href="NAVEGAR {$item->url}" caption="{$item->title}"} - <small><i>{$item->date}</i></small><br/>
<p align="justify"> 
	{$item->kwic}</p>
{/foreach}
</td>
</table>