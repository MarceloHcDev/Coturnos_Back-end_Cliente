<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductsController extends Controller
{
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
            ->with('images') // carrega as imagens de todos os produtos de uma vez (evita N+1)
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

                // Imagens vêm da tabela product_images (já ordenadas por position)
                $main  = $p->images->firstWhere('type', ProductImage::TYPE_MAIN);
                $hover = $p->images->firstWhere('type', ProductImage::TYPE_HOVER);

                return [
                    // ColecaoItem
                    'id'         => $p->id,
                    'name'       => $p->name,
                    'price'      => $promo ? (float) $p->promotional_price : $base,
                    'oldPrice'   => $promo ? $base : null,
                    'image'      => $this->imageUrl($main),
                    // Sem imagem de hover, usa a principal
                    'hoverImage' => $this->imageUrl($hover ?? $main),
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

    /**
     * Monta a URL de entrega a partir do public_id (e da version) salvos no banco.
     * A version faz o Cloudinary servir o arquivo novo quando uma imagem é substituída.
     * Retorna null quando o produto não tem a imagem cadastrada.
     */
    private function imageUrl(?ProductImage $image): ?string
    {
        if ($image === null || $image->public_id === '') {
            return null;
        }

        $version = $image->version ? "v{$image->version}/" : '';

        return sprintf(
            'https://res.cloudinary.com/%s/image/upload/f_auto,q_auto/%s%s',
            config('services.cloudinary.cloud_name'),
            $version,
            $image->public_id
        );
    }
}