<?php

namespace App\Http\Controllers;

use App\Actions\Knowledge\DispatchKnowledgeIngestion;
use App\Actions\Knowledge\RetryKnowledgeIngestion;
use App\Enums\KnowledgeSourceStatus;
use App\Exceptions\KnowledgeRetryException;
use App\Http\Requests\StoreKnowledgeSourceRequest;
use App\Http\Resources\KnowledgeSourceResource;
use App\Jobs\Knowledge\DeleteKnowledgeSource;
use App\Models\Chatbot;
use App\Models\KnowledgeSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class KnowledgeSourceController extends Controller
{
    /** Status endpoint polled by the panel. */
    public function index(Request $request, Chatbot $chatbot): JsonResponse
    {
        Gate::authorize('view', $chatbot);
        Gate::authorize('viewAny', [KnowledgeSource::class, $chatbot]);

        $sources = $chatbot->knowledgeSources()
            ->ownedBy($request->user())
            ->where('status', '!=', KnowledgeSourceStatus::Deleting->value)
            ->orderByDesc('id')
            ->get();

        return KnowledgeSourceResource::collection($sources)
            ->response()
            ->header('Cache-Control', 'no-store');
    }

    public function store(StoreKnowledgeSourceRequest $request, Chatbot $chatbot, DispatchKnowledgeIngestion $ingest): JsonResponse|RedirectResponse
    {
        Gate::authorize('update', $chatbot);
        Gate::authorize('create', [KnowledgeSource::class, $chatbot]);

        $file = $request->file('file');
        $type = $request->fileType();
        $disk = Storage::disk((string) config('knowledge.disk'));

        // Path is built from server controlled values only.
        $path = $file->storeAs(
            "knowledge/{$chatbot->user_id}/{$chatbot->ulid}",
            Str::uuid().'.'.$type->value,
            (string) config('knowledge.disk'),
        );

        try {
            $source = $chatbot->knowledgeSources()->make([
                'original_name' => $this->displayName($file->getClientOriginalName(), $type->value),
                'disk_path' => $path,
                'mime' => $type->mime(),
                'size' => $file->getSize(),
                'status' => KnowledgeSourceStatus::Pending,
            ]);
            $source->user_id = $chatbot->user_id;
            $source->save();
        } catch (\Throwable $e) {
            $disk->delete($path);

            throw $e;
        }

        $ingest($source);

        if ($request->wantsJson()) {
            return (new KnowledgeSourceResource($source))->response()->setStatusCode(201);
        }

        return redirect()->to(route('chatbots.show', $chatbot).'#knowledge')
            ->with('status', 'Documento enviado. O processamento começou.');
    }

    public function destroy(Request $request, Chatbot $chatbot, KnowledgeSource $knowledgeSource): JsonResponse|RedirectResponse
    {
        Gate::authorize('update', $chatbot);
        Gate::authorize('delete', $knowledgeSource);

        if ($knowledgeSource->status !== KnowledgeSourceStatus::Deleting) {
            // Hidden right away; the job removes vectors, file and rows in the background.
            $knowledgeSource->update(['status' => KnowledgeSourceStatus::Deleting, 'error' => null]);
            DeleteKnowledgeSource::dispatch($knowledgeSource);
        }

        if ($request->wantsJson()) {
            return response()->json(null, 202);
        }

        return redirect()->to(route('chatbots.show', $chatbot).'#knowledge')
            ->with('status', 'Documento removido.');
    }

    public function retry(Request $request, Chatbot $chatbot, KnowledgeSource $knowledgeSource, RetryKnowledgeIngestion $retry): JsonResponse|RedirectResponse
    {
        Gate::authorize('update', $chatbot);
        Gate::authorize('retry', $knowledgeSource);

        try {
            $retry($knowledgeSource);
        } catch (KnowledgeRetryException $e) {
            if ($request->wantsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return redirect()->to(route('chatbots.show', $chatbot).'#knowledge')
                ->withErrors(['knowledge' => $e->getMessage()]);
        }

        if ($request->wantsJson()) {
            return (new KnowledgeSourceResource($knowledgeSource->refresh()))->response()->setStatusCode(202);
        }

        return redirect()->to(route('chatbots.show', $chatbot).'#knowledge')
            ->with('status', 'Processamento reiniciado.');
    }

    /** Display only: never used to build a path. */
    private function displayName(string $clientName, string $extension): string
    {
        $name = mb_scrub(basename(str_replace('\\', '/', $clientName)), 'UTF-8');
        $name = preg_replace('/[\p{C}\/\\\\]+/u', '', $name) ?? '';
        $name = trim(Str::limit($name, 200, ''));

        return $name !== '' ? $name : 'documento.'.$extension;
    }
}
