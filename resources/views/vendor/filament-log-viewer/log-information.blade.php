<style>
    .log-container {
        background-color: #ffffff;
        color: #1f2937; /* text-gray-900 */
        border-radius: 0.5rem;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        font-family: sans-serif;
    }

    .log-header {
        background-color: #f9fafb; /* bg-gray-50 */
        padding: 16px 24px;
        border-bottom: 1px solid #e5e7eb; /* border-gray-200 */
    }

    .log-header h3 {
        font-size: 1rem;
        font-weight: 600;
        margin: 0;
    }

    .log-section {
        padding: 16px 24px;
    }

    .log-item {
        display: flex;
        align-items: center;
        padding: 8px 0;
        border-bottom: 1px solid #e5e7eb; /* border-gray-200 */
    }

    .log-label {
        width: 9rem;
        font-size: 0.875rem;
        font-weight: 500;
        margin-right: 12px;
        color: #111827; /* text-gray-900 */
    }

    .log-value {
        font-size: 0.875rem;
        color: #6b7280; /* text-gray-500 */
    }

    .log-meta {
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        padding: 12px 0;
    }

    .log-meta .log-item {
        flex: 1;
        min-width: 200px;
        border-bottom: none;
    }

    .log-footer {
        padding: 8px 0;
        background-color: #f9fafb; /* bg-gray-50 */
        border-top: 1px solid #e5e7eb;
    }

    /* Dark mode (if needed) */
    .dark .log-container {
        background-color: #111827; /* dark:bg-gray-900 */
        color: #f9fafb;
    }
    .dark .log-header,
    .dark .log-footer {
        background-color: #1f2937; /* dark:bg-gray-800 */
        border-color: #374151; /* dark:border-gray-700 */
    }
    .dark .log-label {
        color: #f9fafb;
    }
    .dark .log-value {
        color: #9ca3af; /* dark:text-gray-400 */
    }
</style>

<div class="log-container">
    <div class="log-header">
        <h3>{{ __('filament-log-viewer::log.table.detail.title') }}</h3>
    </div>
    <div class="log-section">
        <div class="log-item">
            <div class="log-label">
                {{ __('filament-log-viewer::log.table.detail.file_path') }}:
            </div>
            <div class="log-value">{{ $data->path() }}</div>
        </div>

        <div class="log-meta">
            <div class="log-item">
                <div class="log-label">
                    {{ __('filament-log-viewer::log.table.detail.log_entries') }}:
                </div>
                <div class="log-value">{{ $data->entries()->count() }}</div>
            </div>
            <div class="log-item">
                <div class="log-label">
                    {{ __('filament-log-viewer::log.table.detail.size') }}:
                </div>
                <div class="log-value">{{ $data->size() }}</div>
            </div>
            <div class="log-item">
                <div class="log-label">
                    {{ __('filament-log-viewer::log.table.detail.created_at') }}:
                </div>
                <div class="log-value">{{ $data->createdAt() }}</div>
            </div>
            <div class="log-item">
                <div class="log-label">
                    {{ __('filament-log-viewer::log.table.detail.updated_at') }}:
                </div>
                <div class="log-value">{{ $data->updatedAt() }}</div>
            </div>
        </div>
    </div>
</div>
<div class="log-footer"></div>
