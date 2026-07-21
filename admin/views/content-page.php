<?php
if (!defined('ABSPATH')) exit;
?>

<div class="wrap chatzio-admin">
    <div class="chatzio-shell">
        <div class="chatzio-page-header">
            <div>
                <h1 class="chatzio-page-title">
                    <span class="dashicons dashicons-update"></span>
                    Content Sync
                </h1>
                <p class="chatzio-page-subtitle">Keep your chatbot updated with the latest posts, pages, and products so answers stay accurate.</p>
            </div>
        </div>

        <div class="chatzio-sync-container">
        
        <div class="sync-stats-grid">
            <div class="stat-card">
                <div class="stat-icon"><span class="dashicons dashicons-admin-post"></span></div>
                <div class="stat-content">
                    <h3><?php echo number_format($stats['posts']); ?></h3>
                    <p>Posts Indexed</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon"><span class="dashicons dashicons-admin-page"></span></div>
                <div class="stat-content">
                    <h3><?php echo number_format($stats['pages']); ?></h3>
                    <p>Pages Indexed</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon"><span class="dashicons dashicons-products"></span></div>
                <div class="stat-content">
                    <h3><?php echo number_format($stats['products']); ?></h3>
                    <p>Products Indexed</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon"><span class="dashicons dashicons-database"></span></div>
                <div class="stat-content">
                    <h3><?php echo number_format($stats['total']); ?></h3>
                    <p>Total Items</p>
                </div>
            </div>
        </div>
        
        <div class="sync-actions chatzio-card">
            <button type="button" id="sync-content-btn" class="button button-primary button-hero">
                <span class="dashicons dashicons-update"></span>
                Sync All Content Now
            </button>

            <div id="sync-progress" class="sync-progress" style="display:none;">
                <div class="progress-bar">
                    <div class="progress-fill"></div>
                </div>
                <p class="progress-text">Syncing content...</p>
            </div>

            <div id="sync-result" class="sync-result"></div>
        </div>

        <div class="sync-info-box chatzio-card chatzio-embed-card">
            <h3><span class="dashicons dashicons-admin-generic"></span> Semantic Search (RAG)</h3>
            <p><strong>Chunks embedded:</strong> <?php echo (int) $stats['chunks_embedded']; ?> / <?php echo (int) $stats['chunks_total']; ?></p>
            <?php if (!empty($stats['last_embedding_sync'])) : ?>
                <p><strong>Last embedding sync:</strong> <?php echo esc_html($stats['last_embedding_sync']); ?></p>
            <?php endif; ?>
            <p class="description">Content is chunked and embedded for semantic search. Sync content first, then re-embed to update embeddings after major content changes.</p>
            <button type="button" id="reembed-chunks-btn" class="button button-secondary">
                <span class="dashicons dashicons-image-filter"></span>
                Re-embed All Chunks
            </button>
            <div id="reembed-result" class="sync-result"></div>
        </div>
        
        <div class="sync-info-box chatzio-card">
            <h3><span class="dashicons dashicons-info"></span> Last Sync Information</h3>
            <p><strong>Last synced:</strong> <?php echo esc_html($stats['last_sync']); ?></p>
            <p class="description">The chatbot will use this content to provide accurate, context-aware responses. Regular syncing ensures your AI has the most up-to-date information.</p>
        </div>
        
        <div class="sync-tips chatzio-card">
            <h3>💡 Tips for Better Results</h3>
            <ul>
                <li>Sync your content after publishing new posts or updating product information</li>
                <li>Use clear, descriptive titles for better AI understanding</li>
                <li>Keep product descriptions detailed and informative</li>
                <li>Enable auto-sync in Advanced Settings for daily updates</li>
            </ul>
        </div>
        
        <div class="sync-info-box chatzio-card chatzio-diagnose-box">
            <h3><span class="dashicons dashicons-search"></span> Diagnose Knowledge Base</h3>
            <p>Check if all your content and resources are properly loaded and available to the AI.</p>
            <button type="button" id="diagnose-btn" class="button button-secondary">
                <span class="dashicons dashicons-visibility"></span>
                Run Diagnostic
            </button>
            <div id="diagnose-result" class="chatzio-diagnose-result"></div>
        </div>
    </div>
</div>
</div>

<script>
jQuery(document).ready(function($) {
    $('#reembed-chunks-btn').on('click', function() {
        var $btn = $(this);
        var $result = $('#reembed-result');
        $btn.prop('disabled', true);
        $result.html('<p class="description">Re-embedding chunks... This may take 30-60 seconds for the AI to process.</p>');
        $.ajax({
            url: chatzioAdmin.ajaxUrl,
            type: 'POST',
            timeout: 180000,
            data: { action: 'chatzio_reembed_chunks', nonce: chatzioAdmin.nonce },
            success: function(response) {
                if (response.success) {
                    $result.html('<div class="chatzio-alert chatzio-alert-success"><strong>Done.</strong> ' + (response.data.message || '') + '</div>');
                    setTimeout(function() { location.reload(); }, 2000);
                } else {
                    $result.html('<div class="chatzio-alert chatzio-alert-error"><strong>Error:</strong> ' + (response.data && response.data.message ? response.data.message : 'Re-embed failed') + '</div>');
                }
            },
            error: function(xhr) {
                var detail = xhr.status ? ' (HTTP ' + xhr.status + ')' : '';
                $result.html('<div class="chatzio-alert chatzio-alert-error"><strong>Request failed' + detail + '.</strong> The embedding may have timed out. Check Logs & Debug for details, or try syncing content first.</div>');
            },
            complete: function() { $btn.prop('disabled', false); }
        });
    });

    $('#diagnose-btn').on('click', function() {
        var $btn = $(this);
        var $result = $('#diagnose-result');
        
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Checking...');
        
        $.ajax({
            url: chatzioAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'chatzio_diagnose_knowledge',
                nonce: chatzioAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    var d = response.data;
                    var html = '<div class="chatzio-diagnose-card">';
                    html += '<h4 class="chatzio-diagnose-title">📊 Knowledge Base Status</h4>';
                    
                    // Resources
                    html += '<p><strong>Resources Table:</strong> ' + (d.resources_table_exists ? '✅ Exists' : '❌ Missing') + '</p>';
                    if (d.resources_count !== undefined) {
                        html += '<p><strong>Uploaded Resources:</strong> ' + d.resources_count + '</p>';
                        if (d.resources && d.resources.length > 0) {
                            html += '<ul class="chatzio-diagnose-list">';
                            d.resources.forEach(function(r) {
                                var status = r.status === 'active' ? '✅' : '⚠️';
                                var safeTitle = (r.title || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
                                html += '<li>' + status + ' ' + safeTitle + ' (' + (r.file_type || '').toUpperCase() + ') - ' + r.content_length + ' chars';
                                if (r.content_preview) {
                                    var safePreview = (r.content_preview.substring(0, 300) + '...').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
                                    html += '<div class="chatzio-diagnose-preview">Preview: ' + safePreview + '</div>';
                                }
                                html += '</li>';
                            });
                            html += '</ul>';
                        }
                    }
                    
                    // Content
                    html += '<p><strong>Content Table:</strong> ' + (d.content_table_exists ? '✅ Exists' : '❌ Missing') + '</p>';
                    if (d.content_stats) {
                        html += '<p><strong>Synced Content:</strong></p><ul class="chatzio-diagnose-list">';
                        d.content_stats.forEach(function(s) {
                            html += '<li>' + s.content_type + ': ' + s.count + '</li>';
                        });
                        html += '</ul>';
                    }
                    
                    if (d.chunk_stats) {
                        html += '<p><strong>RAG Chunks:</strong> ' + d.chunk_stats.embedded + ' embedded / ' + d.chunk_stats.total + ' total</p>';
                    }
                    if (d.sample_context) {
                        html += '<p><strong>Sample Context Test:</strong></p>';
                        html += '<ul class="chatzio-diagnose-list">';
                        html += '<li>Posts loaded: ' + d.sample_context.posts_count + '</li>';
                        html += '<li>Pages loaded: ' + d.sample_context.pages_count + '</li>';
                        html += '<li>Products loaded: ' + d.sample_context.products_count + '</li>';
                        html += '<li>Resources loaded: ' + d.sample_context.resources_count + '</li>';
                        if (d.sample_context.chunks_count !== undefined) {
                            html += '<li>Chunks returned (RAG): ' + d.sample_context.chunks_count + '</li>';
                        }
                        if (d.sample_context.resource_titles && d.sample_context.resource_titles.length > 0) {
                            html += '<li>Resource titles: ' + d.sample_context.resource_titles.join(', ') + '</li>';
                        }
                        html += '</ul>';
                    }
                    
                    html += '</div>';
                    $result.html(html);
                } else {
                    $result.html('<div class="chatzio-alert chatzio-alert-error"><strong>Error:</strong> <span>' + response.data.message + '</span></div>');
                }
            },
            error: function() {
                $result.html('<div class="chatzio-alert chatzio-alert-error"><strong>Connection error.</strong> <span>Please try again.</span></div>');
            },
            complete: function() {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-visibility"></span> Run Diagnostic');
            }
        });
    });
});
</script>
