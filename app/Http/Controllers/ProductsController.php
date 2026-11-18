<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductsController extends Controller
{
    private const FOLDER = 'produtos';
    private const PLACEHOLDER = 'placeholder';

    // GET /api/v1/products?colecao=elite&aba=promocao&busca=coturno&limit=8
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'colecao' => 'nullable|string|max:60',
            'aba'     => 'nullable|in:lancamentos,classicos,promocao',
            'busca'   => 'nullable|string|max:80',
            'limit'   => 'nullable|integer|min:1|max:100',
        ]);

        $itens = Product::query()
            ->where('active', true)
            ->orderBy('id') // antes dos when(): o reorder() da aba sobrescreve
            ->when($data['colecao'] ?? null, fn ($q, $c) => $q->where('collection', $c))
            ->when($data['busca'] ?? null, function ($q, $b) {
                $q->where(function ($w) use ($b) {
                    $w->where('name', 'like', "%{$b}%")
                      ->orWhere('brand', 'like', "%{$b}%")
                      ->orWhere('product_type', 'like', "%{$b}%");
                });
            })
            ->when($data['aba'] ?? null, function ($q, $aba) {
                match ($aba) {
                    'lancamentos' => $q->reorder('created_at', 'desc'),
                    'promocao'    => $q->whereNotNull('promotional_price')
                                       ->whereColumn('promotional_price', '<', 'base_price'),
                    'classicos'   => $q->withCount('orderItems')->reorder('order_items_count', 'desc'),
                };
            })
            ->limit($data['limit'] ?? 8)
            ->get()
            ->map(function (Product $p) {
                $base  = (float) $p->base_price;
                $promo = $p->promotional_price !== null
                    && (float) $p->promotional_price < $base;

                return [
                    // ColecaoItem
                    'id'         => $p->id,
                    'name'       => $p->name,
                    'price'      => $promo ? (float) $p->promotional_price : $base,
                    'oldPrice'   => $promo ? $base : null,
                    'image'      => $this->imageUrl($p->id, 1, self::PLACEHOLDER),
                    'hoverImage' => $this->imageUrl($p->id, 2, "{$p->id}_1"),
                    'badge'      => $p->badge,

                    // Campos extras do tipo Product (listagem / busca).
                    // cor, material, terreno e tamanhos ainda não existem no banco:
                    // seguem vazios até haver a tabela de variantes.
                    'category'   => $promo ? 'promocao' : '',
                    'tipo'       => $p->product_type ?? '',
                    'marca'      => $p->brand ?? '',
                    'cor'        => '',
                    'material'   => '',
                    'terreno'    => '',
                    'tamanhos'   => [],
                ];
            })
            ->values();

        return response()->json($itens);
    }

    private function imageUrl(int $productId, int $n, string $fallback): string
    {
        return sprintf(
            'https://res.cloudinary.com/ciy3wqfm/image/upload/f_auto,q_auto,d_%s:%s/%s/%s_%d',
            config('services.cloudinary.cloud_name'),
            self::FOLDER,
            $fallback,
            self::FOLDER,
            $productId,
            $n
        );
    }
}