<?php

namespace App\Http\Controllers\Ucp;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Ucp\Concerns\AuthorizesAgentCredential;
use App\Models\Merchant;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    use AuthorizesAgentCredential;

    /**
     * Agents hit this for product discovery. It reads the local `products`
     * cache — never the live connector — so response time doesn't depend on
     * the underlying platform's own API latency or rate limits, and one slow
     * upstream store can't take down catalog reads for everyone else.
     */
    public function index(Request $request, Merchant $merchant)
    {
        abort_unless($merchant->hasCapability('catalog'), 404);
        $this->assertCredentialMatches($request, $merchant);

        $products = $merchant->products()
            ->when(
                $request->query('q'),
                fn ($query, $search) => $query->where('title', 'like', "%{$search}%")
            )
            ->paginate(min((int) $request->query('limit', 50), 100));

        return response()->json([
            'products' => $products->map(fn ($product) => [
                'id' => $product->external_id,
                'title' => $product->title,
                'price' => $product->price_cents / 100,
                'currency' => $product->currency,
                'in_stock' => $product->inventory_quantity > 0,
            ]),
            'next_page' => $products->hasMorePages() ? $products->currentPage() + 1 : null,
        ]);
    }
}
