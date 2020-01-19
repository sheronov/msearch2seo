<div class="mse2-ac-item">
    {$idx}. {$seo_link?: $pagetitle}
    {if $weight}
        <span class="mse2-ac-weight">
			<small>{'mse2_weight' | lexicon}: {$weight}</small>
		</span>
    {/if}
    {if $intro}
        <br>
        <small>{$intro}</small>
    {/if}
</div>