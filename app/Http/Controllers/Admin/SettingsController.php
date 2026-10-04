<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Http\Controllers\Controller;
use App\Services\SettingsService;
use App\Services\UploadService;

final class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly Validator $validator,
        private readonly UploadService $uploads,
    ) {
    }

    public function edit(Request $request): Response
    {
        return $this->view('pages/admin/settings', [
            'values' => $this->settings->all(),
            'aboutImage' => $this->settings->aboutImage(),
            'aboutImageIsCustom' => $this->settings->get('about_image') !== '',
        ]);
    }

    public function update(Request $request): Response
    {
        $data = $this->validator->validate($request->all(), SettingsService::EDITABLE);

        // Store a new upload before saving anything, so a rejected file leaves the settings untouched.
        $image = $request->file('about_image');
        $newImage = $image !== null ? $this->uploads->storePublicImage($image, 'about_image', 'site') : null;

        $this->settings->update($data);
        if ($newImage !== null || $request->boolean('remove_about_image')) {
            $this->uploads->deletePublic($this->settings->setAboutImage($newImage) ?: null);
        }
        $this->success('common.flash.saved');

        return $this->redirect('/admin/settings');
    }
}
