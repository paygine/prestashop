{capture name=path}
	<a href="{$link->getPageLink('order', true, NULL, "step=3")|escape:'html':'UTF-8'}" title="{l s='Go back to the Checkout' mod='paygine'}">{l s='Checkout' mod='paygine'}</a><span class="navigation-pipe">{$navigationPipe}</span>{l s='Payment by debit or credit card' mod='paygine'}
{/capture}

{assign var='current_step' value='payment'}
{include file="$tpl_dir./order-steps.tpl"}

{if $nbProducts <= 0}
	<p class="warning">{l s='Your shopping cart is empty.' mod='paygine'}</p>
{else}
<h3>{l s='Payment by debit or credit card' mod='paygine'}</h3>
<form action="{$link->getModuleLink('paygine', 'validation', [], true)|escape:'html'}" method="post">
<p>
	<a href="http://www.paygine.ru" target="_blank"><img src="{$this_path_bw}paygine.png" alt="{l s='Paygine' mod='paygine'}" style="float:left; margin: 0px 10px 5px 0px;" /></a>
	{l s='We accept major kinds of bank cards including Visa and MasterCard in partnership with Paygine, which provides of secure online transactions processing.' mod='paygine'}
	<br/><br />
	{l s='By clicking the \'Make payment\' button below, you will be redirected to Paygine payment gateway to complete the payment.' mod='paygine'}
</p>
<br/>
<p class="cart_navigation" id="cart_navigation">
	<input type="hidden" name="stub" value="stub" />
	<input type="submit" value="{l s='Make payment' mod='paygine'}" class="exclusive_large" />
</p>
</form>
{/if}
