<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class DynamicSectionController extends Controller
{
    public function index()
    {
        return view('dynamic-section'); // Replace with your view file
    }
    public function submit(Request $request)
{
    $validated = $request->validate([
        'input1' => 'required|string|max:255',
        'input2' => 'required|string|max:255',
        'input3' => 'required|string|max:255',
        'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
    ]);

    // Handle the inputs and image upload logic here
    // Example: Save the image and inputs to the database

    return redirect()->back()->with('success', 'Form submitted successfully!');
}
}
