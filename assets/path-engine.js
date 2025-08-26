(function($) {
    function findClosest(el, selector) {
        while (el && el !== document) {
            if (el.matches(selector)) return el;
            el = el.parentElement;
        }
        return null;
    }

    window.psych_open_station_modal = function(button) {
        if (button.disabled) return;
        const stationItem = findClosest(button, '[data-station-node-id]');
        if (!stationItem) return;
        const stationDetails = JSON.parse(stationItem.getAttribute('data-station-details'));
        if (!stationDetails) return;
        const modal = document.getElementById('psych-station-modal');
        const modalTitle = modal.querySelector('.psych-modal-title');
        const modalContent = modal.querySelector('.psych-modal-content');
        modalTitle.textContent = stationDetails.title;
        modalContent.innerHTML = '<div style="text-align:center; padding: 40px;">در حال بارگذاری...</div>';
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        modal.setAttribute('data-current-station-details', JSON.stringify(stationDetails));

        const formData = new FormData();
        formData.append('action', 'psych_path_get_station_content');
        formData.append('nonce', psych_path_vars.nonce);
        formData.append('station_data', JSON.stringify(stationDetails));

        fetch(psych_path_vars.ajax_url, { method: 'POST', body: formData })
            .then(response => response.json())
            .then(res => {
                if (res.success) {
                    document.querySelectorAll('script[id*="gform_"], link[id*="gform_"]').forEach(el => el.remove());

                    modalContent.innerHTML = res.data.html;

                    if (res.data.assets) {
                        const tempDiv = document.createElement('div');
                        tempDiv.innerHTML = res.data.assets;

                        Array.from(tempDiv.children).forEach(node => {
                            if (node.tagName === 'LINK' || (node.tagName === 'SCRIPT' && node.src)) {
                                document.head.appendChild(node.cloneNode(true));
                            }
                        });

                        const inlineScripts = tempDiv.querySelectorAll('script:not([src])');
                        inlineScripts.forEach(script => {
                            try {
                                (new Function(script.innerHTML))();
                            } catch (e) {
                                console.error("Error executing inline GForm script:", e);
                            }
                        });
                    }

                    const formWrapper = modalContent.querySelector('.gform_wrapper');
                    if (formWrapper) {
                        const formId = formWrapper.id.split('_')[1] || 0;

                        function initializeGForm() {
                            if (typeof window.gform !== 'undefined' && typeof window.jQuery !== 'undefined') {
                                const $ = window.jQuery;

                                const correctSpinnerUrl = psych_path_vars.gform_spinner_url;
                                if (typeof gformInitSpinner === 'function') {
                                    gformInitSpinner(formId, correctSpinnerUrl);
                                }

                                $(document).trigger('gform_post_render', [formId, 1]);
                                gform.doAction('gform_post_render', formId, 1);
                                $(formWrapper).css('display', 'block');
                            } else {
                                setTimeout(initializeGForm, 50);
                            }
                        }

                        initializeGForm();
                    }
                } else {
                    modalContent.innerHTML = `<p>${res.data.message || 'خطا در بارگذاری محتوا.'}</p>`;
                }
            });
    };

    window.psych_complete_mission_inline = function(button) {
        if (button.disabled) return;
        let stationItem, stationDetails, pathContainer;
        const modal = findClosest(button, '#psych-station-modal');
        if (modal) {
            stationDetails = JSON.parse(modal.getAttribute('data-current-station-details'));
            if (!stationDetails) return;
            stationItem = document.querySelector(`[data-station-node-id="${stationDetails.station_node_id}"]`);
            if (!stationItem) return;
            pathContainer = findClosest(stationItem, '.psych-path-container');
        } else {
            stationItem = findClosest(button, '[data-station-node-id]');
            if (!stationItem) return;
            stationDetails = JSON.parse(stationItem.getAttribute('data-station-details'));
            pathContainer = findClosest(button, '.psych-path-container');
        }
        if (!stationItem || !stationDetails || !pathContainer) { return; }

        const originalHtml = button.innerHTML;
        button.disabled = true;
        button.innerHTML = 'در حال پردازش...';
        const formData = new FormData();
        formData.append('action', 'psych_path_complete_mission');
        formData.append('nonce', psych_path_vars.nonce);
        formData.append('node_id', stationDetails.station_node_id);
        formData.append('station_data', JSON.stringify(stationDetails));
        if (button.hasAttribute('data-rewards')) {
            formData.append('custom_rewards', button.getAttribute('data-rewards'));
        }
        fetch(psych_path_vars.ajax_url, { method: 'POST', body: formData })
            .then(response => response.json())
            .then(response => {
                if (response.success) {
                    if (modal && modal.style.display !== 'none') {
                        psych_close_station_modal();
                    }
                    stationItem.classList.remove('open');
                    stationItem.classList.add('completed');
                    psych_show_rewards_notification(response.data.rewards, () => {
                        psych_update_all_ui(pathContainer);
                    });
                } else {
                    button.disabled = false;
                    button.innerHTML = originalHtml;
                    alert(response.data.message || 'خطا در تکمیل ماموریت.');
                }
            });
    };

    window.psych_close_station_modal = function() {
        const modal = document.getElementById('psych-station-modal');
        if (modal) {
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }
    };

    function psych_show_rewards_notification(rewards, callback) {
        let rewardsHtml = '<ul>';
        let hasRewards = false;
        if (rewards && rewards.points) { hasRewards = true; rewardsHtml += `<li><i class="fas fa-star"></i> شما <strong>${rewards.points}</strong> امتیاز کسب کردید!</li>`; }
        if (rewards && rewards.badge) { hasRewards = true; rewardsHtml += `<li><i class="fas fa-medal"></i> نشان <strong>"${rewards.badge}"</strong> را دریافت نمودید!</li>`; }
        if (rewardsHtml === '<ul>') rewardsHtml += '<li><i class="fas fa-check-circle"></i> با موفقیت انجام شد!</li>';
        rewardsHtml += '</ul>';
        const notification = document.createElement('div');
        notification.className = 'psych-rewards-overlay';
        notification.innerHTML = `<div class="psych-rewards-popup"><div class="psych-rewards-header"><i class="fas fa-gift"></i><h3>عالی بود!</h3></div><div class="psych-rewards-body">${rewardsHtml}</div><button class="psych-rewards-close">ادامه می‌دهم</button></div>`;
        document.body.appendChild(notification);
        if (hasRewards && typeof confetti === 'function') confetti({ particleCount: 150, spread: 90, origin: { y: 0.6 } });
        const closeHandler = function() {
            notification.style.opacity = '0';
            setTimeout(() => {
                notification.remove();
                if (typeof callback === 'function') callback();
            }, 300);
        };
        notification.querySelector('.psych-rewards-close').addEventListener('click', closeHandler);
    }

    function psych_update_all_ui(pathContainer) {
        if (!pathContainer) return;
        const userCompletedStations = {};
        pathContainer.querySelectorAll('.completed[data-station-node-id], .psych-accordion-item.completed').forEach(el => {
            userCompletedStations[el.getAttribute('data-station-node-id')] = true;
        });
        let previousStationCompleted = true;
        const statusRegex = /\s*\b(open|locked|completed|restricted)\b/g;
        pathContainer.querySelectorAll('[data-station-node-id]').forEach(station => {
            const details = JSON.parse(station.getAttribute('data-station-details')) || {};
            const nodeId = details.station_node_id;
            let newStatus = 'locked';
            let newIsUnlocked = false;
            const isReadyToUnlock = details.unlock_trigger === 'independent' || previousStationCompleted;
            if (userCompletedStations[nodeId]) {
                newStatus = 'completed';
                newIsUnlocked = true;
            } else if (isReadyToUnlock) {
                newStatus = 'open';
                newIsUnlocked = true;
            }
            details.status = newStatus;
            details.is_unlocked = newIsUnlocked;
            details.is_completed = (newStatus === 'completed');
            station.setAttribute('data-station-details', JSON.stringify(details));
            const badge = station.querySelector('.psych-status-badge');
            const icon = station.querySelector('.psych-accordion-icon i, .psych-timeline-icon i');
            station.className = station.className.replace(statusRegex, '').trim() + ' ' + newStatus;
            if (badge) {
                badge.className = 'psych-status-badge ' + newStatus;
                if (newStatus === 'completed') badge.innerHTML = '<i class="fas fa-check"></i> تکمیل شده';
                else if (newStatus === 'open') badge.innerHTML = '<i class="fas fa-unlock"></i> باز';
                else badge.innerHTML = '<i class="fas fa-lock"></i> قفل';
            }
            if(icon) {
                if (newStatus === 'completed') icon.className = 'fas fa-check-circle';
                else icon.className = details.icon || 'fas fa-lock';
            }
            if (details.unlock_trigger === 'sequential') {
                previousStationCompleted = (newStatus === 'completed');
            }
        });
        const total = pathContainer.querySelectorAll('[data-station-node-id]').length;
        const completedCount = Object.keys(userCompletedStations).length;
        const percentage = total > 0 ? Math.round((completedCount / total) * 100) : 0;
        const progressFill = pathContainer.querySelector('.psych-progress-fill');
        const progressText = pathContainer.querySelector('.psych-progress-text');
        const progressPercentage = pathContainer.querySelector('.psych-progress-percentage');
        if(progressText) progressText.textContent = `پیشرفت: ${completedCount} از ${total} ایستگاه`;
        if(progressPercentage) progressPercentage.textContent = `${percentage}%`;
        if(progressFill) progressFill.style.width = `${percentage}%`;
    }

    function psych_refresh_next_station(stationItem) {
        if (!stationItem) return;
        const nextStation = stationItem.nextElementSibling;
        if (nextStation && nextStation.matches('.psych-accordion-item') && nextStation.classList.contains('open')) {
            const stationData = nextStation.getAttribute('data-station-details');
            const formData = new FormData();
            formData.append('action', 'psych_path_get_inline_station_content');
            formData.append('nonce', psych_path_vars.nonce);
            formData.append('station_data', stationData);
            fetch(psych_path_vars.ajax_url, { method: 'POST', body: formData })
                .then(response => response.json())
                .then(res => {
                    if (res.success) {
                        const missionContent = nextStation.querySelector('.psych-accordion-mission-content');
                        if(missionContent) missionContent.innerHTML = res.data.html;
                        const contentArea = nextStation.querySelector('.psych-accordion-content');
                        if (contentArea) {
                            contentArea.style.display = 'block';
                        }
                    }
                });
        }
    }

    document.addEventListener('click', function(e) {
        if (e.target.matches('.psych-modal-close') || findClosest(e.target, '.psych-modal-close') || e.target.matches('.psych-modal-overlay')) {
            psych_close_station_modal();
        }
        const header = findClosest(e.target, '.psych-accordion-header');
        if (header && !e.target.matches('button, a')) {
            const content = header.nextElementSibling;
            if (content && content.matches('.psych-accordion-content')) {
                const isOpening = content.style.display !== 'block';
                const container = findClosest(header, '.psych-accordion');
                if(container) {
                    container.querySelectorAll('.psych-accordion-content').forEach(el => {
                        if (el !== content) el.style.display = 'none';
                    });
                }
                content.style.display = isOpening ? 'block' : 'none';
            }
        }
    });

    if (typeof jQuery !== 'undefined') {
        $(document).on('gform_confirmation_loaded', function(event, formId){
            if (document.querySelector('.psych-path-container')) {
                setTimeout(function() {
                    location.reload();
                }, 500);
            }
        });
    }
})(jQuery);
