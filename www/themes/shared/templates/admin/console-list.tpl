<h1>{$page->title}</h1>

<div class="well well-sm">
{if $consolelist}
{$pager}

<table style="margin-top:10px;" class="data table table-striped responsive-utilities jambo-table Sortable">

	<tr>
		<th>id</th>
		<th>Title</th>
		<th>Platform</th>
		<th>Created</th>
	</tr>

	{foreach from=$consolelist item=console}
	<tr class="{cycle values=",alt"}">
		<td class="less">{$console.id}</td>
		<td><a title="Edit" href="{$smarty.const.WWW_TOP}/console-edit.php?id={$console.id}">{$console.title}</a></td>
		<td>{$console.platform}</td>
		<td>{$console.createddate|date_format}</td>
	</tr>
	{/foreach}

</table>

<br/>
{$pager}
{else}
<p>No games available.</p>
{/if}
</div>
