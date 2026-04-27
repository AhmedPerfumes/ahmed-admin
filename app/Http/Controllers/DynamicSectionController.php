<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\DynamicSection;

class DynamicSectionController extends Controller
{
    public function index()
{
    $sections = DynamicSection::all();
    return view('dynamic-section', compact('sections'));
}

    public function submit(Request $request)
{
    $validated = $request->validate([
        'heading' => 'required|string|max:255',
            'description' => 'required|string',
            'link' => 'required',
            'image' => '',   // assuming mediaImage returns string ID or URL
            'video1' => 'required|string',
            'video2' => 'required|string',
    ]);
    // $imagePath = $request->file('image')->store('images', 'public'); // Store the image in the 'public/images' directory

    DynamicSection::create([
        'heading' => $validated['heading'],
        'description' => $validated['description'],
        'link' => $validated['link'],
        'image' => $validated['image'], 
        'video1' => $validated['video1'],
        'video2' => $validated['video2'],
    ]); 

    return redirect()->back()->with('success', 'Form submitted successfully!');
}
public function destroy($id)
{
    DynamicSection::destroy($id);
    return redirect()->back()->with('success', 'Entry deleted successfully!');
}
}
