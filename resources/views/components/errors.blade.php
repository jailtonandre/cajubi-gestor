@if($errors->any())
    <div role="alert" class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
        <p class="font-semibold">Revise as informações para continuar.</p>
        <ul class="mt-2 grid list-inside list-disc gap-1">
            @foreach($errors->all() as $message)<li wire:key="error-{{ $loop->index }}">{{ $message }}</li>@endforeach
        </ul>
    </div>
@endif

