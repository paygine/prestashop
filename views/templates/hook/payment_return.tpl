{if $status == 'ok'}
<p>{l s='Your order on %s is complete.' sprintf=$shop_name mod='paygine'}
{else}
<p class="warning">
	{l s='We noticed a problem with your order. If you think this is an error, feel free to contact our' mod='paygine'}
	<a href="{$link->getPageLink('contact', true)|escape:'html'}">{l s='expert customer support team' mod='paygine'}</a>.
</p>
{/if}
