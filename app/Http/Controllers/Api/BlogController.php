<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Botble\Blog\Models\Post;
use Botble\Ecommerce\Models\Product;
use Botble\Slug\Models\Slug;

class BlogController extends Controller
{
    public function getBlogs(Request $request)
    {
        $limit = (int)$request['limit'];
        $page = (int)$request['page'];

        $blogs = Post::select('id', 'name', 'description', 'image', 'created_at')->where('status', 'published')->whereDoesntHave('categories', function ($query) { $query->where('categories.name', 'news-article'); })->orderBy('id', 'DESC')->paginate($limit);

        foreach ($blogs as $key => $val) {
            $val->permalink = Slug::select('key')->where('reference_id', $val->id)->where('reference_type', 'Botble\Blog\Models\Post')->first();
        }

         $response = response()->json($blogs)->header('Cache-Control', 'public, max-age=86400, s-maxage=172800')->setEtag(md5(json_encode($blogs)));  // Cache 1 Day in the browser, 2 Days at Cloudflare

        if ($response->isNotModified(request())) {
            return $response;
        }

        return $response;
    }
    
    public function getNewsArticles(Request $request)
    {
        $limit = (int)$request['limit'];
        $page = (int)$request['page'];

        $blogs = Post::select('id', 'name', 'description', 'image', 'created_at')
            ->where('status', 'published')
            // This line ensures we ONLY get posts from the 'news-article' category
            ->whereHas('categories', function ($query) {
                $query->where('categories.name', 'news-article');
            })
            ->orderBy('id', 'DESC')
            ->paginate($limit);

        foreach ($blogs as $key => $val) {
            $val->permalink = Slug::select('key')->where('reference_id', $val->id)->where('reference_type', 'Botble\Blog\Models\Post')->first();
        }

        $response = response()->json($blogs)->header('Cache-Control', 'public, max-age=86400, s-maxage=172800')->setEtag(md5(json_encode($blogs))); // Cache 1 Day in the browser, 2 Days at Cloudflare

        if ($response->isNotModified(request())) {
            return $response;
        }

        return $response;
    }


    public function getBlogDetails(Request $request)
    {
        $blog = $request['blog'];
        
        // $blog = Post::select('id', 'name', 'content', 'created_at')->where('status', 'published')
        $blog = DB::table('posts')
        // ->where('name', $blog)
        // ->select(DB::raw("REGEXP_REPLACE(REPLACE(REPLACE(name, ' &amp; ', '&'), '&', ' '),'[^a-zA-Z0-9-]', '')"))
        ->join('post_categories', 'post_categories.post_id', '=', 'posts.id', 'left')
        ->join('categories', 'categories.id', '=', 'post_categories.category_id', 'left')
        ->join('slugs', 'posts.id', '=', 'slugs.reference_id', 'left')
        ->select('posts.id', 'posts.name', 'posts.content', 'posts.image', 'posts.created_at', 'categories.name as category_name')
        ->where('posts.status', 'published')
        ->where('categories.status', 'published')
        // ->where(DB::raw("REGEXP_REPLACE(REPLACE(REPLACE(posts.name, '&amp;', '&'), '&', ' '),'[^a-zA-Z0-9-]', '')"), '=', implode('', explode(' ', $blog)))
        ->where('reference_type', 'Botble\Blog\Models\Post')
        ->where('key', $blog)
        ->first();

        // if(!$blog) {
        //     return response()->json([
        //         'message' => 'No Blog Found'
        //     ]);
        // }

        $response = response()->json($blog)->header('Cache-Control', 'public, max-age=86400, s-maxage=172800')->setEtag(md5(json_encode($blog)));  // Cache 1 Day in the browser, 2 Days at Cloudflare

        if ($response->isNotModified(request())) {
            return $response;
        }

        return $response;
    }

    public function getBlogSEO(Request $request)
    {
        $blog = $request['blog'];
        
        // $blog = Post::select('id', 'name', 'content', 'created_at')->where('status', 'published')
        $blg = DB::table('posts')
        // ->where('name', $blog)
        // ->select(DB::raw("REGEXP_REPLACE(REPLACE(REPLACE(name, ' &amp; ', '&'), '&', ' '),'[^a-zA-Z0-9-]', '')"))
        ->join ('meta_boxes', 'meta_boxes.reference_id', '=', 'posts.id', 'left')
        // ->join('categories', 'categories.id', '=', 'post_categories.category_id', 'left')
        // ->select('posts.id', 'posts.name', 'posts.content', 'posts.image', 'posts.created_at', 'categories.name as category_name')
        ->select('meta_value')
        ->where('posts.status', 'published')
        // ->where('categories.status', 'published')
        ->where(DB::raw("REGEXP_REPLACE(REPLACE(REPLACE(posts.name, '&amp;', '&'), '&', ' '),'[^a-zA-Z0-9-]', '')"), '=', implode('', explode(' ', $blog)))
        ->where('meta_key', 'seo_meta')
        ->where('reference_type', 'Botble\Blog\Models\Post')
        ->first();

        // if(!$blog) {
        //     return response()->json([
        //         'message' => 'No Blog Found'
        //     ]);
        // }

        $response = response()->json($blg)->header('Cache-Control', 'public, max-age=86400, s-maxage=172800')->setEtag(md5(json_encode($blg)));  // Cache 1 Day in the browser, 2 Days at Cloudflare

        if ($response->isNotModified(request())) {
            return $response;
        }

        return $response;
    }
}
