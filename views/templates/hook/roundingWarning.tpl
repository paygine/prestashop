{*
/*
    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as
    published by the Free Software Foundation.
    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.
    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/**
 *  @author    Paygine
 *  @copyright 2019-2022 Paygine
 *  @license   LICENSE.txt
 *
 */
*}

<div class="alert alert-warning" data-rounding-alert>
  <button type="button" class="close" data-dismiss="alert">×</button>
    <div>
      {$message|unescape:'html'}
    </div>
	<ul>
    {if $round_mode}
		<li>
			{l s='Round mode: "Round up away from zero, when it is half way there (recommended)"' mod='paygine'}
		</li>
		{/if}
    {if $round_type}
		<li>
			{l s='Round type: "Round on each item"' mod='paygine'}
		</li>
    {/if}
    {if $currencies}
		<li>
			{l s='Number of decimals' d='Admin.Shopparameters.Feature'}: "2" ( {foreach from=$currencies item=$currency name=currency_obj}
        {if !$smarty.foreach.currency_obj.first}, {/if}<a href="{$currency['url']}" target="_blank">{$currency['iso_code']}</a>
			{/foreach})
		</li>
    {/if}
	</ul>
</div>
