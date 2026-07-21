<?php
if (!defined('ABSPATH')) exit;
$is_pro = isset($is_pro) ? (bool) $is_pro : (class_exists('Chatzio_License') && Chatzio_License::is_pro());
$upgrade_url = apply_filters('chatzio_upgrade_url', 'https://chatzio.pro/pricing');

// Get filter values
$search_query = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
$type_filter = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : '';
$active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'library';

// Validate tab
if (!in_array($active_tab, ['library', 'upload', 'paste'])) {
    $active_tab = 'library';
}

// Filter resources
$filtered_resources = $resources;
if (!empty($search_query)) {
    $filtered_resources = array_filter($resources, function($r) use ($search_query) {
        return stripos($r['title'], $search_query) !== false || stripos($r['filename'], $search_query) !== false;
    });
}
if (!empty($type_filter)) {
    $filtered_resources = array_filter($filtered_resources, function($r) use ($type_filter) {
        return $r['file_type'] === $type_filter;
    });
}

// Stats
$total_resources = count($resources);
$total_size = array_reduce($resources, function($carry, $r) { return $carry + strlen($r['content']); }, 0);
$file_types = array_unique(array_column($resources, 'file_type'));
?>

<div class="wrap chatzio-admin chatzio-resources-v2">
    <div class="chatzio-pro-page-lock <?php echo $is_pro ? 'is-pro' : 'is-free'; ?>">
    <?php if (!$is_pro): ?>
        <div class="chatzio-pro-page-lock-overlay">
            <div class="chatzio-pro-page-lock-glass">
                <div class="chatzio-pro-page-lock-badge"><?php esc_html_e('Pro Feature', 'chatzio-ai'); ?></div>
                <h2><?php esc_html_e('Unlock this in Pro', 'chatzio-ai'); ?></h2>
                <p><?php esc_html_e('Resources train Chatzio on your private business knowledge and can deliver up to 200-300% better response performance for context-heavy customer questions.', 'chatzio-ai'); ?></p>
                <ul class="chatzio-pro-page-lock-list">
                    <li><?php esc_html_e('Upload PDFs, docs, and internal reference files', 'chatzio-ai'); ?></li>
                    <li><?php esc_html_e('200-300% stronger business-context answer quality', 'chatzio-ai'); ?></li>
                    <li><?php esc_html_e('Consistent replies across support and sales chats', 'chatzio-ai'); ?></li>
                </ul>
                <div class="chatzio-pro-page-lock-actions">
                    <a class="button button-primary button-hero" target="_blank" rel="noopener" href="<?php echo esc_url($upgrade_url); ?>"><?php esc_html_e('Upgrade to Pro', 'chatzio-ai'); ?></a>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=chatzio-plans')); ?>"><?php esc_html_e('Compare plans', 'chatzio-ai'); ?></a>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <div class="chatzio-shell">
        <div class="chatzio-page-header">
            <div>
                <h1 class="chatzio-page-title">
                    <span class="dashicons dashicons-portfolio"></span>
                    Resource Library
                </h1>
                <p class="chatzio-page-subtitle">Upload documents and knowledge sources for your AI assistant.</p>
            </div>
            <div class="header-stats">
                <span class="stat-item"><strong><?php echo $total_resources; ?></strong> resources</span>
                <span class="stat-item"><strong><?php echo size_format($total_size); ?></strong> total</span>
            </div>
        </div>
        
        <!-- Unified Tab System: Library, Upload, Paste -->
        <div class="resources-unified-tabs">
            <a href="<?php echo esc_url(admin_url('admin.php?page=chatzio-resources&tab=library')); ?>" class="unified-tab <?php echo $active_tab === 'library' ? 'active' : ''; ?>">
                <span class="dashicons dashicons-list-view"></span> Resource Library
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=chatzio-resources&tab=upload')); ?>" class="unified-tab <?php echo $active_tab === 'upload' ? 'active' : ''; ?>">
                <span class="dashicons dashicons-cloud-upload"></span> Upload
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=chatzio-resources&tab=paste')); ?>" class="unified-tab <?php echo $active_tab === 'paste' ? 'active' : ''; ?>">
                <span class="dashicons dashicons-edit"></span> Paste
            </a>
        </div>

        <?php if ($active_tab === 'upload'): ?>
        <!-- Upload Tab -->
        <div class="resources-tab-content">
            <div class="chatzio-card">
                <form id="resource-upload-form" enctype="multipart/form-data">
                    <div class="upload-area" id="upload-area">
                        <div class="upload-icon">
                            <span class="dashicons dashicons-cloud-upload"></span>
                        </div>
                        <h3>Drag & drop files here</h3>
                        <p>or click to browse</p>
                        <p class="upload-formats">Supported: TXT, DOCX, PDF</p>
                        <input type="file" id="resource-file-input" name="resource_file" accept=".pdf,.doc,.docx,.txt" class="chatzio-hidden-file">
                        <button type="button" class="button button-secondary" id="select-file-btn">
                            Choose File
                        </button>
                    </div>
                    
                    <div id="upload-progress" class="upload-progress" style="display:none;">
                        <div class="progress-bar">
                            <div class="progress-fill"></div>
                        </div>
                        <p class="progress-text">Processing document...</p>
                    </div>
                    
                    <div id="upload-result" class="upload-result"></div>
                </form>
            </div>
        </div>

        <?php elseif ($active_tab === 'paste'): ?>
        <!-- Paste Tab -->
        <div class="resources-tab-content">
            <div class="chatzio-card">
                <form id="paste-resource-form">
                    <div class="form-grid">
                        <div class="chatzio-field">
                            <label for="resource-title">Resource Title *</label>
                            <input type="text" id="resource-title" name="resource_title" class="chatzio-input" placeholder="e.g., Company FAQ, Product Guide" required>
                        </div>
                        <div class="chatzio-field full-width">
                            <label for="resource-content">Content *</label>
                            <textarea id="resource-content" name="resource_content" rows="14" class="chatzio-textarea" placeholder="Paste your FAQ, documentation, or any text content here...&#10;&#10;The AI will use this content to answer customer questions." required></textarea>
                            <p class="description">Tip: Paste FAQs, product descriptions, policies, or any text the AI should know about.</p>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="button button-primary button-large" id="save-paste-btn">
                            <span class="dashicons dashicons-saved"></span> Save Resource
                        </button>
                    </div>
                    <div id="paste-result" class="upload-result"></div>
                </form>
            </div>
        </div>

        <?php else: ?>
        <!-- Library Tab Content -->
        <div class="resources-tab-content">
            <!-- Filters Bar (consistent with other pages) -->
            <div class="chatzio-filters-bar">
                <form method="get" action="" class="filters-form">
                    <input type="hidden" name="page" value="chatzio-resources">
                    <input type="hidden" name="tab" value="library">
                    
                    <div class="filter-group search-group">
                        <span class="dashicons dashicons-search"></span>
                        <input type="search" name="s" value="<?php echo esc_attr($search_query); ?>" placeholder="Search resources...">
                    </div>
                    
                    <?php if (!empty($search_query)): ?>
                    <div class="filter-actions">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=chatzio-resources&tab=library')); ?>" class="button">Clear</a>
                    </div>
                    <?php endif; ?>
                </form>
            </div>

        <?php if (empty($filtered_resources)): ?>
            <div class="chatzio-empty-state chatzio-card">
                <span class="dashicons dashicons-portfolio"></span>
                <?php if (!empty($search_query) || !empty($type_filter)): ?>
                    <p>No resources match your search</p>
                    <p class="description">Try a different search term.</p>
                <?php else: ?>
                    <p>No resources yet</p>
                    <p class="description">Add your first resource to help the AI answer questions better.</p>
                    <div class="chatzio-empty-actions">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=chatzio-resources&tab=upload')); ?>" class="button button-primary">
                            <span class="dashicons dashicons-cloud-upload"></span> Upload File
                        </a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=chatzio-resources&tab=paste')); ?>" class="button button-secondary">
                            <span class="dashicons dashicons-edit"></span> Paste Content
                        </a>
                    </div>
                <?php endif; ?>
                </div>
            <?php else: ?>
            <div class="chatzio-card chatzio-table-card">
                <table class="chatzio-table resources-table">
                    <thead>
                        <tr>
                            <th width="5%">Type</th>
                            <th width="35%">Title</th>
                            <th width="25%">Filename</th>
                            <th width="10%">Size</th>
                            <th width="10%">Status</th>
                            <th width="10%">Date</th>
                            <th width="5%"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($filtered_resources as $resource): ?>
                            <tr data-resource-id="<?php echo esc_attr($resource['id']); ?>">
                                <td>
                                    <span class="sc-type-badge type-<?php echo esc_attr($resource['file_type']); ?>"><?php echo strtoupper(esc_html($resource['file_type'])); ?></span>
                                </td>
                                <td>
                                    <strong><?php echo esc_html($resource['title']); ?></strong>
                                </td>
                                <td>
                                    <span class="sc-muted-text"><?php echo esc_html($resource['filename']); ?></span>
                                </td>
                                <td><?php echo size_format(strlen($resource['content'])); ?></td>
                                <td>
                                    <span class="sc-status-badge status-<?php echo esc_attr($resource['status']); ?>"><?php echo esc_html(ucfirst($resource['status'])); ?></span>
                                </td>
                                <td>
                                    <span class="sc-muted-text"><?php echo date('M j, Y', strtotime($resource['uploaded_at'])); ?></span>
                                </td>
                                <td>
                                    <div style="display:flex;gap:4px;">
                                        <button type="button" class="sc-icon-btn edit-resource-btn" data-resource-id="<?php echo esc_attr($resource['id']); ?>" data-resource-title="<?php echo esc_attr($resource['title']); ?>" data-resource-content="<?php echo esc_attr($resource['content']); ?>" title="Edit">
                                            <span class="dashicons dashicons-edit-large"></span>
                                        </button>
                                        <button type="button" class="sc-icon-btn sc-danger delete-resource-btn" data-resource-id="<?php echo esc_attr($resource['id']); ?>" title="Delete">
                                            <span class="dashicons dashicons-trash"></span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>
</div>
</div>

<!-- Edit Resource Modal -->
<div id="edit-resource-modal" class="chatzio-edit-modal-wrap">
    <div class="chatzio-edit-modal-overlay"></div>
    <div class="chatzio-edit-modal">
        <div class="chatzio-edit-modal-header">
            <div class="chatzio-edit-modal-icon">
                <span class="dashicons dashicons-edit-large"></span>
            </div>
            <div>
                <h2>Edit Resource</h2>
                <p class="description">Update the title or content of this resource.</p>
            </div>
            <button type="button" class="chatzio-edit-modal-close" id="close-edit-modal">
                <span class="dashicons dashicons-no-alt"></span>
            </button>
        </div>
        <form id="edit-resource-form">
            <input type="hidden" id="edit-resource-id" name="resource_id" value="">
            <div class="chatzio-edit-modal-body">
                <div class="chatzio-field">
                    <label for="edit-resource-title">Title</label>
                    <input type="text" id="edit-resource-title" name="title" class="chatzio-input" placeholder="Resource title" required>
                </div>
                <div class="chatzio-field">
                    <label for="edit-resource-content">Content</label>
                    <textarea id="edit-resource-content" name="content" rows="18" class="chatzio-textarea" placeholder="Resource content..." required></textarea>
                    <p class="description">This is the plain text the AI uses to answer questions.</p>
                </div>
                <div id="edit-result"></div>
            </div>
            <div class="chatzio-edit-modal-footer">
                <button type="button" class="button" id="cancel-edit-btn">Cancel</button>
                <button type="submit" class="button button-primary" id="save-edit-btn">
                    <span class="dashicons dashicons-saved"></span> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<style>
/* Resources Page v2 Styles */
.wrap.chatzio-admin.chatzio-resources-v2 .header-stats {
    display: flex;
    gap: 20px;
}

.wrap.chatzio-admin .header-stats .stat-item {
    font-size: 14px;
    color: var(--sc-muted);
}

.wrap.chatzio-admin .header-stats .stat-item strong {
    color: var(--sc-text);
}

/* Unified Tab System (3 tabs: Library, Upload, Paste) */
.wrap.chatzio-admin .resources-unified-tabs {
    display: flex;
    gap: 6px;
    margin-bottom: 24px;
    background: var(--sc-surface);
    border: 1px solid var(--sc-border);
    border-radius: 14px;
    padding: 6px;
    width: 100%;
    max-width: 600px;
}

.wrap.chatzio-admin .unified-tab {
    flex: 1;
    padding: 12px 20px;
    font-size: 14px;
    font-weight: 500;
    color: var(--sc-muted);
    text-decoration: none;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border-radius: 10px;
    transition: all 0.2s;
    text-align: center;
}

.wrap.chatzio-admin .unified-tab:hover {
    color: var(--sc-text);
    background: var(--sc-surface-alt);
}

.wrap.chatzio-admin .unified-tab.active {
    background: var(--sc-primary);
    color: white;
}

.wrap.chatzio-admin .unified-tab .dashicons {
    font-size: 18px;
    width: 18px;
    height: 18px;
}

/* Tab Content Container */
.wrap.chatzio-admin .resources-tab-content {
    animation: chatzio-fadeInUp 0.3s ease;
}

/* Form Styles */
.wrap.chatzio-admin .form-grid {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.wrap.chatzio-admin .form-actions {
    margin-top: 20px;
}

.wrap.chatzio-admin .form-actions .button-large {
    padding: 12px 28px;
    font-size: 14px;
}

.wrap.chatzio-admin .form-actions .dashicons {
    margin-right: 6px;
    vertical-align: text-bottom;
}

/* Upload Area */
.wrap.chatzio-admin .upload-area {
    border: 2px dashed var(--sc-border);
    border-radius: 16px;
    padding: 48px 32px;
    text-align: center;
    transition: all 0.2s;
    cursor: pointer;
}

.wrap.chatzio-admin .upload-area:hover {
    border-color: var(--sc-primary);
    background: var(--sc-primary-soft);
}

.wrap.chatzio-admin .upload-area.dragover {
    border-color: var(--sc-primary);
    background: var(--sc-primary-soft);
}

.wrap.chatzio-admin .upload-icon {
    width: 64px;
    height: 64px;
    background: var(--sc-primary-soft);
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 16px;
}

.wrap.chatzio-admin .upload-icon .dashicons {
    font-size: 32px;
    width: 32px;
    height: 32px;
    color: var(--sc-primary);
}

.wrap.chatzio-admin .upload-area h3 {
    margin: 0 0 8px;
    font-size: 16px;
    font-weight: 600;
    color: var(--sc-text);
}

.wrap.chatzio-admin .upload-area p {
    margin: 0 0 8px;
    font-size: 14px;
    color: var(--sc-muted);
}

.wrap.chatzio-admin .upload-formats {
    font-size: 12px !important;
    color: var(--sc-muted) !important;
    margin-bottom: 20px !important;
}

/* Resources Grid */
.wrap.chatzio-admin .resources-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 20px;
}

.wrap.chatzio-admin .resource-card {
    background: var(--sc-surface);
    border: 1px solid var(--sc-border);
    border-radius: 16px;
    overflow: hidden;
    position: relative;
    transition: all 0.2s;
}

.wrap.chatzio-admin .resource-card:hover {
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
    border-color: var(--sc-primary);
}

.wrap.chatzio-admin .resource-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px;
    background: var(--sc-surface-alt);
    border-bottom: 1px solid var(--sc-border);
}

.wrap.chatzio-admin .resource-type-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 700;
    color: white;
}

.wrap.chatzio-admin .resource-type-icon.type-txt { background: linear-gradient(135deg, #64748b, #94a3b8); }
.wrap.chatzio-admin .resource-type-icon.type-pdf { background: linear-gradient(135deg, #EF4444, #f87171); }
.wrap.chatzio-admin .resource-type-icon.type-doc,
.wrap.chatzio-admin .resource-type-icon.type-docx { background: linear-gradient(135deg, #3B82F6, #60a5fa); }
.wrap.chatzio-admin .resource-type-icon.type-paste { background: linear-gradient(135deg, #16A34A, #22c55e); }

.wrap.chatzio-admin .resource-delete-btn {
    width: 32px;
    height: 32px;
    padding: 0;
    border: none;
    background: transparent;
    color: var(--sc-muted);
    cursor: pointer;
    border-radius: 8px;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
}

.wrap.chatzio-admin .resource-delete-btn:hover {
    background: var(--sc-danger-soft);
    color: var(--sc-danger);
}

.wrap.chatzio-admin .resource-body {
    padding: 16px;
}

.wrap.chatzio-admin .resource-title {
    margin: 0 0 6px;
    font-size: 15px;
    font-weight: 600;
    color: var(--sc-text);
    word-break: break-word;
}

.wrap.chatzio-admin .resource-filename {
    margin: 0;
    font-size: 12px;
    color: var(--sc-muted);
    word-break: break-word;
}

.wrap.chatzio-admin .resource-footer {
    display: flex;
    justify-content: space-between;
    padding: 12px 16px;
    border-top: 1px solid var(--sc-border);
    font-size: 12px;
    color: var(--sc-muted);
}

.wrap.chatzio-admin .resource-status {
    position: absolute;
    top: 16px;
    right: 56px;
    padding: 4px 8px;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    border-radius: 6px;
}

.wrap.chatzio-admin .resource-status.status-active {
    background: var(--sc-success-soft);
    color: var(--sc-success);
}

.wrap.chatzio-admin .resource-status.status-processing {
    background: var(--sc-warning-soft);
    color: #92400E;
}

.wrap.chatzio-admin .resource-status.status-failed {
    background: var(--sc-danger-soft);
    color: var(--sc-danger);
}

/* =============================================
   EDIT RESOURCE MODAL — self-contained styles
   All rules use !important to override WP admin
   ============================================= */

#edit-resource-modal.chatzio-edit-modal-wrap {
    display: none !important;
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    bottom: 0 !important;
    width: 100% !important;
    height: 100% !important;
    z-index: 100100 !important;
    align-items: center !important;
    justify-content: center !important;
    margin: 0 !important;
    padding: 0 !important;
    box-sizing: border-box !important;
}

#edit-resource-modal.chatzio-edit-modal-wrap.is-open {
    display: flex !important;
}

#edit-resource-modal .chatzio-edit-modal-overlay {
    position: absolute !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    bottom: 0 !important;
    width: 100% !important;
    height: 100% !important;
    background: rgba(15, 23, 42, 0.45) !important;
    backdrop-filter: blur(4px) !important;
    -webkit-backdrop-filter: blur(4px) !important;
    margin: 0 !important;
    padding: 0 !important;
}

#edit-resource-modal .chatzio-edit-modal {
    position: relative !important;
    background: #ffffff !important;
    border-radius: 16px !important;
    width: 90% !important;
    max-width: 720px !important;
    max-height: 85vh !important;
    overflow: hidden !important;
    display: flex !important;
    flex-direction: column !important;
    box-shadow: 0 24px 48px -12px rgba(15, 23, 42, 0.25), 0 0 0 1px #e2e8f0 !important;
    animation: chatzio-modal-slideUp 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
    margin: 0 !important;
    padding: 0 !important;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
}

@keyframes rotation {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

@keyframes chatzio-modal-slideUp {
    from { opacity: 0; transform: translateY(20px) scale(0.97); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

/* — Header — */
#edit-resource-modal .chatzio-edit-modal-header {
    display: flex !important;
    align-items: center !important;
    gap: 16px !important;
    padding: 24px 28px !important;
    margin: 0 !important;
    border-bottom: 1px solid #e2e8f0 !important;
    background: #ffffff !important;
    box-sizing: border-box !important;
}

#edit-resource-modal .chatzio-edit-modal-icon {
    width: 44px !important;
    height: 44px !important;
    min-width: 44px !important;
    background: #eef2ff !important;
    border-radius: 12px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-shrink: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
}

#edit-resource-modal .chatzio-edit-modal-icon .dashicons {
    font-size: 20px !important;
    width: 20px !important;
    height: 20px !important;
    color: #4f46e5 !important;
    margin: 0 !important;
    padding: 0 !important;
}

#edit-resource-modal .chatzio-edit-modal-header h2 {
    margin: 0 !important;
    padding: 0 !important;
    font-size: 18px !important;
    font-weight: 700 !important;
    color: #0f172a !important;
    line-height: 1.3 !important;
}

#edit-resource-modal .chatzio-edit-modal-header p.description {
    margin: 4px 0 0 0 !important;
    padding: 0 !important;
    font-size: 13px !important;
    color: #64748b !important;
    font-style: normal !important;
}

#edit-resource-modal .chatzio-edit-modal-close {
    margin-left: auto !important;
    margin-top: 0 !important;
    margin-bottom: 0 !important;
    margin-right: 0 !important;
    background: transparent !important;
    border: none !important;
    cursor: pointer !important;
    color: #64748b !important;
    padding: 8px !important;
    border-radius: 10px !important;
    transition: all 0.15s ease !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    box-shadow: none !important;
    outline: none !important;
}

#edit-resource-modal .chatzio-edit-modal-close:hover {
    background: #f1f5f9 !important;
    color: #0f172a !important;
}

#edit-resource-modal .chatzio-edit-modal-close .dashicons {
    font-size: 22px !important;
    width: 22px !important;
    height: 22px !important;
}

/* — Body — */
#edit-resource-modal .chatzio-edit-modal-body {
    padding: 28px !important;
    margin: 0 !important;
    overflow-y: auto !important;
    flex: 1 1 auto !important;
    box-sizing: border-box !important;
}

#edit-resource-modal .chatzio-edit-modal-body .chatzio-field {
    display: flex !important;
    flex-direction: column !important;
    gap: 8px !important;
    margin: 0 0 20px 0 !important;
    padding: 0 !important;
}

#edit-resource-modal .chatzio-edit-modal-body .chatzio-field:last-of-type {
    margin-bottom: 0 !important;
}

#edit-resource-modal .chatzio-edit-modal-body label {
    display: block !important;
    font-weight: 600 !important;
    font-size: 14px !important;
    color: #0f172a !important;
    margin: 0 !important;
    padding: 0 !important;
    line-height: 1.4 !important;
}

#edit-resource-modal .chatzio-edit-modal-body .chatzio-input,
#edit-resource-modal .chatzio-edit-modal-body input[type="text"] {
    width: 100% !important;
    max-width: 100% !important;
    padding: 10px 14px !important;
    margin: 0 !important;
    border: 1px solid #e2e8f0 !important;
    border-radius: 10px !important;
    background: #ffffff !important;
    font-size: 14px !important;
    font-family: inherit !important;
    color: #0f172a !important;
    line-height: 1.5 !important;
    box-shadow: none !important;
    box-sizing: border-box !important;
    -webkit-appearance: none !important;
    appearance: none !important;
    outline: none !important;
    transition: border-color 0.15s ease, box-shadow 0.15s ease !important;
}

#edit-resource-modal .chatzio-edit-modal-body .chatzio-input:focus,
#edit-resource-modal .chatzio-edit-modal-body input[type="text"]:focus {
    border-color: #4f46e5 !important;
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15) !important;
}

#edit-resource-modal .chatzio-edit-modal-body .chatzio-textarea,
#edit-resource-modal .chatzio-edit-modal-body textarea {
    width: 100% !important;
    max-width: 100% !important;
    min-height: 340px !important;
    padding: 14px 16px !important;
    margin: 0 !important;
    border: 1px solid #e2e8f0 !important;
    border-radius: 10px !important;
    background: #ffffff !important;
    font-size: 13px !important;
    font-family: 'SF Mono', 'Menlo', 'Monaco', 'Consolas', 'Liberation Mono', monospace !important;
    color: #0f172a !important;
    line-height: 1.75 !important;
    box-shadow: none !important;
    box-sizing: border-box !important;
    resize: vertical !important;
    -webkit-appearance: none !important;
    appearance: none !important;
    outline: none !important;
    transition: border-color 0.15s ease, box-shadow 0.15s ease !important;
}

#edit-resource-modal .chatzio-edit-modal-body .chatzio-textarea:focus,
#edit-resource-modal .chatzio-edit-modal-body textarea:focus {
    border-color: #4f46e5 !important;
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15) !important;
}

#edit-resource-modal .chatzio-edit-modal-body p.description {
    margin: 0 !important;
    padding: 0 !important;
    font-size: 12px !important;
    color: #64748b !important;
    font-style: normal !important;
}

#edit-resource-modal #edit-result {
    margin-top: 16px !important;
}

#edit-resource-modal #edit-result:empty {
    margin: 0 !important;
    display: none !important;
}

#edit-resource-modal #edit-result .notice {
    margin: 0 !important;
    border-radius: 10px !important;
    padding: 12px 16px !important;
}

/* — Footer — */
#edit-resource-modal .chatzio-edit-modal-footer {
    display: flex !important;
    justify-content: flex-end !important;
    align-items: center !important;
    gap: 12px !important;
    padding: 18px 28px !important;
    margin: 0 !important;
    border-top: 1px solid #e2e8f0 !important;
    background: #f8fafc !important;
    box-sizing: border-box !important;
}

#edit-resource-modal .chatzio-edit-modal-footer .button {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 6px !important;
    padding: 10px 22px !important;
    margin: 0 !important;
    font-size: 14px !important;
    font-weight: 500 !important;
    border-radius: 10px !important;
    cursor: pointer !important;
    transition: all 0.15s ease !important;
    line-height: 1.4 !important;
    text-decoration: none !important;
    box-shadow: none !important;
}

#edit-resource-modal .chatzio-edit-modal-footer .button:not(.button-primary) {
    background: #ffffff !important;
    border: 1px solid #e2e8f0 !important;
    color: #0f172a !important;
}

#edit-resource-modal .chatzio-edit-modal-footer .button:not(.button-primary):hover {
    background: #f1f5f9 !important;
    border-color: #cbd5e1 !important;
}

#edit-resource-modal .chatzio-edit-modal-footer .button.button-primary {
    background: #4f46e5 !important;
    border: 1px solid #4f46e5 !important;
    color: #ffffff !important;
}

#edit-resource-modal .chatzio-edit-modal-footer .button.button-primary:hover {
    background: #4338ca !important;
    border-color: #4338ca !important;
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3) !important;
}

#edit-resource-modal .chatzio-edit-modal-footer .button.button-primary .dashicons {
    font-size: 16px !important;
    width: 16px !important;
    height: 16px !important;
    color: #ffffff !important;
}

/* Edit button in table row */
.wrap.chatzio-admin .edit-resource-btn {
    color: #4f46e5 !important;
}
.wrap.chatzio-admin .edit-resource-btn:hover {
    background: #eef2ff !important;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Auto-submit search on Enter (simplified search)
    $('.resources-search-input').on('keypress', function(e) {
        if (e.which === 13) {
            $(this).closest('form').submit();
        }
    });

    // Paste form handled by admin.js (initResourcePaste)
    // Delete resource handled by admin.js (initResourceDelete)

    // Edit Resource Modal
    var $modal = $('#edit-resource-modal');

    function openEditModal(id, title, content) {
        $('#edit-resource-id').val(id);
        $('#edit-resource-title').val(title);
        $('#edit-resource-content').val(content);
        $('#edit-result').html('');
        $modal.addClass('is-open');
        $('body').css('overflow', 'hidden');
    }

    function closeEditModal() {
        $modal.removeClass('is-open');
        $('body').css('overflow', '');
    }

    $(document).on('click', '.edit-resource-btn', function() {
        var id = $(this).data('resource-id');
        var title = $(this).data('resource-title');
        var content = $(this).data('resource-content');
        openEditModal(id, title, content);
    });

    $('#close-edit-modal, #cancel-edit-btn, .chatzio-edit-modal-overlay').on('click', function() {
        closeEditModal();
    });

    // Prevent closing when clicking inside modal content
    $('.chatzio-edit-modal').on('click', function(e) {
        e.stopPropagation();
    });

    // Close on Escape key
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && $modal.is(':visible')) {
            closeEditModal();
        }
    });

    $('#edit-resource-form').on('submit', function(e) {
        e.preventDefault();
        var $btn = $('#save-edit-btn');
        var resourceId = $('#edit-resource-id').val();
        var title = $('#edit-resource-title').val().trim();
        var content = $('#edit-resource-content').val().trim();

        if (!title || !content) {
            $('#edit-result').html('<div class="notice notice-error"><p>Title and content are required.</p></div>');
            return;
        }

        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update" style="animation:rotation 1s linear infinite;"></span> Saving...');
        $('#edit-result').html('');

        $.ajax({
            url: chatzioAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'chatzio_update_resource',
                nonce: chatzioAdmin.nonce,
                resource_id: resourceId,
                title: title,
                content: content
            },
            success: function(response) {
                if (response.success) {
                    $btn.html('<span class="dashicons dashicons-update" style="animation:rotation 1s linear infinite;"></span> Redirecting...');
                    location.reload();
                } else {
                    var msg = response.data && response.data.message ? response.data.message : 'Failed to update resource';
                    $('#edit-result').html('<div class="notice notice-error"><p>' + msg + '</p></div>');
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Save Changes');
                }
            },
            error: function() {
                $('#edit-result').html('<div class="notice notice-error"><p>Failed to update resource. Please try again.</p></div>');
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Save Changes');
            }
        });
    });
});
</script>
