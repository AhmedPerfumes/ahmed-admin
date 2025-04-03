<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\DynamicSection;

class DynamicSectionController extends Controller
{
    public function index()
    {
        return view('dynamic-section'); // Replace with your view file
    }
    public function submit(Request $request)
{
    $validated = $request->validate([
        'section1_title' => 'required|string|max:255',
        'section1_subtitle' => 'required|string|max:255',
        'section1_description' => 'required|string|max:255',
        'image' => 'required|max:2048', 
    ]);
    // $imagePath = $request->file('image')->store('images', 'public'); // Store the image in the 'public/images' directory

    DynamicSection::create([
        'title' => $validated['section1_title'],
        'subtitle' => $validated['section1_subtitle'],
        'description' => $validated['section1_description'],
        'image' => $validated['image'], 
    ]);

    return redirect()->back()->with('success', 'Form submitted successfully!');
}
}
