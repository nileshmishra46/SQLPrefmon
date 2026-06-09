        </div> <!-- End of main-content -->
    </div> <!-- End of app-container -->

    <!-- Global Layout Helpers & Interactive Animations -->
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            // General Client-Side Micro-Animations / Tab Switchers
            const tabs = document.querySelectorAll(".tab-btn");
            if (tabs.length > 0) {
                tabs.forEach(tab => {
                    tab.addEventListener("click", function() {
                        const tabContainer = this.closest(".tabs-container");
                        const tabTarget = this.getAttribute("data-tab");
                        
                        // Deactivate all sibling buttons
                        tabContainer.querySelectorAll(".tab-btn").forEach(btn => btn.classList.remove("active"));
                        // Deactivate all sibling panes
                        tabContainer.querySelectorAll(".tab-pane").forEach(pane => pane.classList.remove("active"));
                        
                        // Activate clicked button
                        this.classList.add("active");
                        // Activate target pane
                        const targetPane = tabContainer.querySelector(`#${tabTarget}`);
                        if (targetPane) {
                            targetPane.classList.add("active");
                        }
                    });
                });
            }
        });
        
        // Fix script clipboard helper
        function copyToClipboard(elementId, button) {
            const copyText = document.getElementById(elementId).innerText;
            navigator.clipboard.writeText(copyText).then(() => {
                const originalText = button.innerHTML;
                button.innerHTML = '<i class="fa-solid fa-check"></i> Copied!';
                button.style.backgroundColor = 'var(--color-success)';
                button.style.color = '#ffffff';
                setTimeout(() => {
                    button.innerHTML = originalText;
                    button.style.backgroundColor = '';
                    button.style.color = '';
                }, 2000);
            }).catch(err => {
                console.error('Failed to copy: ', err);
            });
        }
    </script>
</body>
</html>
