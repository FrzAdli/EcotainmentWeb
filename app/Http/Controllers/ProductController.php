<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Storage;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = $request->query('search');
        $category = $request->query('category');
        $sortBy = $request->query('sort_by', 'created_at');
        $sortOrder = $request->query('sort_order', 'desc');
        $includeDeleted = $request->query('include_deleted', false); // Tambahan parameter

        $productsQuery = Product::query();

        // Include produk yang dihapus jika diminta
        if ($includeDeleted) {
            $productsQuery->withTrashed();
        }

        if ($category) {
            $productsQuery->where('category', $category);
        }

        if ($search) {
            $productsQuery->where('name', 'like', '%' . $search . '%');
        }

        $productsQuery->orderBy($sortBy, $sortOrder);

        $products = $productsQuery->with('reviews')
            ->withAvg('reviews', 'rating')
            ->get();

        $products = $products->map(function ($product) {
            $averageRating = $product->reviews_avg_rating ?? 0;
            $averageRating = number_format($averageRating, 1);

            return [
                'id' => $product->id,
                'name' => $product->name,
                'price' => $product->price,
                'category' => $product->category,
                'description' => $product->description,
                'image' => $product->image,
                'total_sales' => $product->total_sales,
                'average_rating' => $averageRating,
                'deleted_at' => $product->deleted_at, // Tambahkan jika ada soft delete
                'created_at' => $product->created_at,
                'updated_at' => $product->updated_at,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Berhasil mengambil produk',
            'data' => $products,
        ], 200);
    }


    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'category' => 'required|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|file|image|mimes:jpeg,png,jpg,gif,webp',
        ]);

        $imageUrl = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('images', 'public');
            $imageUrl = Storage::url($imagePath);
        }

        $product = Product::create([
            'name' => $request->name,
            'price' => intval($request->price),
            'category' => $request->category,
            'description' => $request->description,
            'image' => $imageUrl,
            'total_sales' => 0,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Produk berhasil ditambahkan',
            'data' => $product,
        ], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {
            // Cari produk berdasarkan ID
            $product = Product::findOrFail($id);

            // Validasi input
            $request->validate([
                'name' => 'nullable|string|max:255',
                'price' => 'nullable|numeric|min:0',
                'category' => 'nullable|string|max:255',
                'description' => 'nullable|string',
                'image' => 'nullable|file|image|mimes:jpeg,png,jpg,gif,webp',
            ]);

            // Update field jika ada input
            if ($request->has('name')) {
                $product->name = $request->name;
            }
            if ($request->has('price')) {
                $product->price = intval($request->price);
            }
            if ($request->has('category')) {
                $product->category = $request->category;
            }
            if ($request->has('description')) {
                $product->description = $request->description;
            }

            // Update gambar jika ada
            if ($request->hasFile('image')) {
                // Hapus gambar lama
                if ($product->image) {
                    Storage::delete('public/' . str_replace('/storage/', '', $product->image));
                }
                // Simpan gambar baru
                $imagePath = $request->file('image')->store('images', 'public');
                $product->image = Storage::url($imagePath);
            }

            // Simpan perubahan
            $product->save();

            return response()->json([
                'success' => true,
                'message' => 'Produk berhasil diperbarui',
                'data' => $product,
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Produk tidak ditemukan',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan pada server',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    public function showByProductId($id): JsonResponse
    {
        try {
            $product = Product::with([
                'reviews.user',
                'reviews' => function ($query) {
                    $query->select('id', 'user_id', 'product_id', 'rating', 'comment', 'created_at');
                }
            ])
                ->withAvg('reviews', 'rating')
                ->findOrFail($id);

            $averageRating = number_format($product->reviews_avg_rating ?? 0, 1);

            return response()->json([
                'success' => true,
                'message' => 'Berhasil mengambil produk dan ulasan',
                'data' => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'price' => $product->price,
                    'category' => $product->category,
                    'description' => $product->description,
                    'image' => $product->image,
                    'total_sales' => $product->total_sales,
                    'average_rating' => $averageRating,
                    'reviews' => $product->reviews,
                    'created_at' => $product->created_at,
                    'updated_at' => $product->updated_at,
                ],
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Produk tidak ditemukan',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan pada server',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $product = Product::findOrFail($id);

            // if ($product->image) {
            //     Storage::delete('public/' . str_replace('/storage/', '', $product->image));
            // }

            $product->delete();

            return response()->json([
                'success' => true,
                'message' => 'Produk berhasil dihapus',
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Produk tidak ditemukan',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan pada server',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

}
