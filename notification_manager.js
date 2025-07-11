// Fajl: /assets/js/script.js

document.addEventListener('DOMContentLoaded', function() {
    // --- Dohvaćanje DOM elemenata za Notifikacijski Centar ---
    var notificationCenterToggle = document.querySelector('.notification-center-toggle');
    var totalUnreadCountBadge = document.querySelector('.total-unread-count');
    var notificationDropdownPanel = document.querySelector('.notification-dropdown-panel');
    var closePanelBtn = document.querySelector('.close-panel-btn');

    var tabButtons = document.querySelectorAll('.panel-tabs .tab-btn');
    var tabContents = document.querySelectorAll('.panel-content .tab-content');
    var messagesTabCount = document.querySelector('.messages-tab-count');
    var notificationsTabCount = document.querySelector('.notifications-tab-count');
    var adminTabCount = document.querySelector('.admin-tab-count');

    // --- Dohvaćanje DOM elemenata za Chat Widget (Facebook-like) ---
    var chatWidget = document.getElementById('chat-widget');
    var chatHeader = document.querySelector('#chat-widget .chat-header');
    var chatMinimizeBtn = document.getElementById('chat-minimize-btn');
    var chatCloseBtn = document.getElementById('chat-close-btn');
    var chatPartnerName = document.getElementById('chat-partner-name');
    var chatMessagesContainer = document.getElementById('chat-messages-container');
    var chatMessageInput = document.getElementById('chat-message-input');
    var chatSendBtn = document.getElementById('chat-send-btn');

    // --- Funkcije za dohvaćanje i ažuriranje brojača ---
    function fetchUnreadCounts() {
        fetch('/api/notifications/get_counts.php')
            .then(function(response) {
                if (!response.ok) {
                    return response.text().then(function(text) {
                        throw new Error("HTTP error! Status: " + response.status + ", Message: " + text);
                    });
                }
                return response.json();
            })
            .then(function(data) {
                // Ažuriranje ukupnog brojača na glavnoj ikoni
                if (totalUnreadCountBadge) {
                    totalUnreadCountBadge.textContent = data.total_unread_count;
                    if (data.total_unread_count > 0) {
                        totalUnreadCountBadge.classList.remove('hidden');
                    } else {
                        totalUnreadCountBadge.classList.add('hidden');
                    }
                }

                // Ažuriranje brojača na tabovima unutar panela
                if (messagesTabCount) {
                    messagesTabCount.textContent = data.unread_messages;
                    if (data.unread_messages > 0) {
                        messagesTabCount.classList.remove('hidden');
                    } else {
                        messagesTabCount.classList.add('hidden');
                    }
                }
                if (notificationsTabCount) {
                    notificationsTabCount.textContent = data.unread_action_notifications;
                    if (data.unread_action_notifications > 0) {
                        notificationsTabCount.classList.remove('hidden');
                    } else {
                        notificationsTabCount.classList.add('hidden');
                    }
                }
                if (adminTabCount) {
                    adminTabCount.textContent = data.unread_admin_notifications;
                    if (data.unread_admin_notifications > 0) {
                        adminTabCount.classList.remove('hidden');
                    } else {
                        adminTabCount.classList.add('hidden');
                    }
                }
            })
            .catch(function(error) {
                console.error('Error fetching unread counts:', error);
            });
    }

    // --- Funkcije za otvaranje/zatvaranje panela ---
    function openNotificationPanel() {
        notificationDropdownPanel.classList.remove('hidden');
        // Učitaj sadržaj aktivnog taba kada se panel otvori
        var activeTabBtn = document.querySelector('.panel-tabs .tab-btn.active');
        if (activeTabBtn) {
            loadTabContent(activeTabBtn.dataset.tab);
        } else {
            // Ako nema aktivnog taba po defaultu, aktiviraj 'messages' tab
            activateTab('messages');
        }
    }

    function closeNotificationPanel() {
        notificationDropdownPanel.classList.add('hidden');
    }

    // --- Funkcije za upravljanje tabovima ---
    function activateTab(tabName) {
        // Ukloni 'active' sa svih tab dugmadi i sadržaja
        tabButtons.forEach(function(btn) {
            btn.classList.remove('active');
        });
        tabContents.forEach(function(content) {
            content.classList.remove('active');
        });

        // Dodaj 'active' na kliknuto dugme
        var activeBtn = document.querySelector('.tab-btn[data-tab="' + tabName + '"]');
        if (activeBtn) activeBtn.classList.add('active');

        // Pokaži odgovarajući sadržaj taba
        var activeContent = document.getElementById(tabName + '-tab-content');
        if (activeContent) activeContent.classList.add('active');

        // Učitaj sadržaj taba
        loadTabContent(tabName);
    }

    function loadTabContent(tabName) {
        var contentArea = document.getElementById(tabName + '-tab-content');
        if (!contentArea) return; // Osiguraj da element postoji

        contentArea.innerHTML = '<p class="loading-message">Učitavanje ' + tabName + '...</p>'; // Privremeni placeholder

        var apiUrl = '';
        if (tabName === 'messages') {
            apiUrl = '/api/notifications/get_messages_preview.php';
        } else if (tabName === 'notifications') {
            apiUrl = '/api/notifications/get_action_notifications.php';
        } else if (tabName === 'admin_alerts') {
            apiUrl = '/api/notifications/get_admin_notifications.php';
        }

        if (apiUrl) {
            fetch(apiUrl)
                .then(function(response) {
                    if (!response.ok) {
                        return response.text().then(function(text) {
                            throw new Error("HTTP error! Status: " + response.status + ", Message: " + text);
                        });
                    }
                    return response.json();
                })
                .then(function(data) {
                    if (data.success) {
                        if (tabName === 'messages') {
                            displayMessagesPreview(data.conversations, contentArea);
                        } else if (tabName === 'notifications') {
                            displayActionNotifications(data.notifications, contentArea);
                        } else if (tabName === 'admin_alerts') {
                            displayAdminAlerts(data.admin_alerts, contentArea);
                        }
                    } else {
                        contentArea.innerHTML = '<p class="error-message">Greška pri učitavanju ' + tabName + ': ' + (data.error || 'Nepoznata greška') + '</p>';
                        console.error('API error for ' + tabName + ':', data.error);
                    }
                })
                .catch(function(error) {
                    contentArea.innerHTML = '<p class="error-message">Došlo je do pogreške: ' + error.message + '</p>';
                    console.error('Fetch error for ' + tabName + ':', error);
                });
        }
    }

    // --- Funkcija za prikaz pregleda poruka (AŽURIRANA ZA CHAT WIDGET) ---
    function displayMessagesPreview(conversations, contentArea) {
        contentArea.innerHTML = ''; // Očisti prethodni sadržaj

        if (conversations.length === 0) {
            contentArea.innerHTML = '<p class="no-content-message">Nemate aktivnih razgovora.</p>';
            return;
        }

        var ul = document.createElement('ul');
        ul.classList.add('conversation-list');

        conversations.forEach(function(conv) {
            var li = document.createElement('li');
            li.classList.add('conversation-item');
            if (conv.unread_count > 0) {
                li.classList.add('unread'); // Dodaj klasu za nepročitane
            }
            // Dodaj data atribute za ID razgovora i partnera, korisno za otvaranje chata
            li.dataset.conversationId = conv.conversation_id;
            li.dataset.partnerId = conv.partner_employee_id;
            li.dataset.conversationType = conv.conversation_type; // Dohvati tip razgovora
            li.dataset.partnerName = conv.partner_name; // Dodajemo i partner_name ovdje

            var lastMessageSnippet = conv.last_message_content ? conv.last_message_content.substring(0, 50) + (conv.last_message_content.length > 50 ? '...' : '') : 'Nema poruka.';
            var timestamp = conv.last_message_timestamp ? new Date(conv.last_message_timestamp).toLocaleString('hr-HR') : '';

            li.innerHTML = `
                <div class="conversation-header">
                    <span class="partner-name">${conv.partner_name}</span>
                    <span class="partner-company">${conv.partner_company}</span>
                    ${conv.unread_count > 0 ? '<span class="unread-badge">' + conv.unread_count + '</span>' : ''}
                </div>
                <div class="conversation-body">
                    <p class="last-message-snippet">${lastMessageSnippet}</p>
                    <span class="message-timestamp">${timestamp}</span>
                </div>
            `;
            ul.appendChild(li);
        });
        contentArea.appendChild(ul);

        // Dodaj event listenere za klik na razgovor
        ul.querySelectorAll('.conversation-item').forEach(function(item) {
            item.addEventListener('click', function() {
                var convId = this.dataset.conversationId;
                var partnerId = this.dataset.partnerId;
                var partnerName = this.dataset.partnerName; // Sada dohvaćamo direktno iz data atributa
                
                // Pozivamo našu novu openChatWidget funkciju
                openChatWidget(convId, partnerName, partnerId); // Redoslijed argumenata je promijenjen za novu funkciju
                closeNotificationPanel(); // Zatvori glavni notifikacijski panel
            });
        });
    }

    // --- Funkcija za prikaz akcionih notifikacija ---
    function displayActionNotifications(notifications, contentArea) {
        contentArea.innerHTML = ''; // Očisti prethodni sadržaj

        if (notifications.length === 0) {
            contentArea.innerHTML = '<p class="no-content-message">Trenutno nemate novih obavijesti.</p>';
            return;
        }

        var ul = document.createElement('ul');
        ul.classList.add('notification-list');

        notifications.forEach(function(notification) {
            var li = document.createElement('li');
            li.classList.add('notification-item');
            if (notification.is_read == 0) {
                li.classList.add('unread');
            }

            var notificationText = notification.message || 'Nova obavijest.';
            var timestamp = notification.timestamp ? new Date(notification.timestamp).toLocaleString('hr-HR') : '';

            li.innerHTML = `
                <div class="notification-content">
                    <p>${notificationText}</p>
                    <span class="notification-timestamp">${timestamp}</span>
                </div>
            `;
            
            // Dodajemo click event listener
            if (notification.link) { // Provjeravamo postoji li link
                li.style.cursor = 'pointer'; // Vizualno naznačimo da je klikabilno
                li.addEventListener('click', function() {
                    // Preusmjeravanje na link kada se klikne na notifikaciju
                    window.location.href = notification.link;

                    // Opcionalno: Označavanje notifikacije kao pročitane nakon klika
                    markNotificationAsRead(notification.id); 
                });
            }

            ul.appendChild(li);
        });
        contentArea.appendChild(ul);
    }

    // --- Funkcija za prikaz admin obavijesti ---
    function displayAdminAlerts(adminAlerts, contentArea) {
        contentArea.innerHTML = ''; // Očisti prethodni sadržaj

        if (adminAlerts.length === 0) {
            contentArea.innerHTML = '<p class="no-content-message">Trenutno nemate sistemskih obavijesti.</p>';
            return;
        }

        var ul = document.createElement('ul');
        ul.classList.add('admin-alert-list');

        adminAlerts.forEach(function(alert) {
            var li = document.createElement('li');
            li.classList.add('admin-alert-item');
            if (alert.is_read == 0) {
                li.classList.add('unread');
            }

            var alertText = alert.message || 'Nova sistemska obavijest.';
            var timestamp = alert.timestamp ? new Date(alert.timestamp).toLocaleString('hr-HR') : '';

            li.innerHTML = `
                <div class="admin-alert-content">
                    <p>${alertText}</p>
                    <span class="admin-alert-timestamp">${timestamp}</span>
                </div>
            `;
            // Možete dodati i click event listener ako želite da alert otvara nešto
            // li.addEventListener('click', function() { /* ... */ });

            ul.appendChild(li);
        });
        contentArea.appendChild(ul);
    }

    // Opcionalna funkcija za označavanje notifikacije kao pročitane (potrebna backend implementacija)
    function markNotificationAsRead(notificationId) {
        fetch('/api/notifications/mark_as_read.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ notification_id: notificationId })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                console.log('Notification ' + notificationId + ' marked as read.');
                // Osvježite brojače nakon što je notifikacija pročitana
                fetchUnreadCounts(); 
            } else {
                console.error('Failed to mark notification as read:', data.error);
            }
        })
        .catch(error => console.error('Error marking notification as read:', error));
    }


    // --- Facebook-like Chat Widget Logika (JEDNA FUNKCIJA openChatWidget) ---
    // AŽURIRANA FUNKCIJA openChatWidget sa 3 parametra
    function openChatWidget(conversationId, partnerName, partnerId) {
        if (!chatWidget) {
            console.error("Chat widget element not found. Please ensure #chat-widget exists in your HTML.");
            return;
        }
        chatWidget.classList.remove('hidden');
        chatWidget.classList.remove('minimized'); // Osiguraj da nije minimiziran
        chatPartnerName.textContent = partnerName || 'Nepoznat korisnik'; // Postavi ime partnera

        // Spremi ID-eve u data atribute na chatWidget elementu
        chatWidget.dataset.conversationId = conversationId; 
        chatWidget.dataset.partnerId = partnerId; 
        // Dohvati ID trenutnog korisnika iz sesije (ako ga imate globalno definiranog u JS-u, inače ga dohvatite iz PHP-a)
        // Za sada, pretpostavljamo da currentEmployeeId NIJE potreban direktno u openChatWidget, jer je partnerId ono što nam treba.
        // Ako je potrebno, morat ćete ga proslijediti sa PHP strane ili dohvaćati iz globalne JS varijable.
        // npr. chatWidget.dataset.currentEmployeeId = '<?= $_SESSION['employee_id'] ?? '' ?>'; // iz PHP-a

        // Ovdje ćemo kasnije učitavati stvarne poruke
        chatMessagesContainer.innerHTML = '<p style="text-align: center; color: #888;">Učitavanje poruka...</p>';
        // Pozovi funkciju za učitavanje poruka
        // loadChatMessages(conversationId, partnerId); // Implementiraj ovo kasnije
    }

    // Funkcija za zatvaranje chat widgeta
    function closeChatWidget() {
        if (chatWidget) {
            chatWidget.classList.add('hidden');
        }
    }

    // Funkcija za minimiziranje/maksimiziranje chat widgeta
    function toggleMinimizeChatWidget() {
        if (chatWidget) {
            chatWidget.classList.toggle('minimized');
            // Skrolajte do dna kada se maksimizira
            if (!chatWidget.classList.contains('minimized')) {
                chatMessagesContainer.scrollTop = chatMessagesContainer.scrollHeight;
            }
        }
    }

    // Event Listeneri za chat widget
    if (chatHeader) {
        chatHeader.addEventListener('click', function(event) {
            // Ako klik nije na minimize/close dugme, onda toggle minimiziranje
            if (event.target !== chatMinimizeBtn && event.target !== chatCloseBtn) {
                toggleMinimizeChatWidget();
            }
        });
    }

    if (chatMinimizeBtn) {
        chatMinimizeBtn.addEventListener('click', toggleMinimizeChatWidget);
    }

    if (chatCloseBtn) {
        chatCloseBtn.addEventListener('click', closeChatWidget);
    }


    // --- Event Listeneri za Notifikacijski Centar ---
    if (notificationCenterToggle) {
        notificationCenterToggle.addEventListener('click', function(event) {
            event.preventDefault();
            if (notificationDropdownPanel.classList.contains('hidden')) {
                openNotificationPanel();
            } else {
                closeNotificationPanel();
            }
        });
    }

    if (closePanelBtn) {
        closePanelBtn.addEventListener('click', closeNotificationPanel);
    }

    // Zatvori panel klikom izvan njega
    document.addEventListener('click', function(event) {
        var isClickInsideToggle = notificationCenterToggle && notificationCenterToggle.contains(event.target);
        var isClickInsidePanel = notificationDropdownPanel && notificationDropdownPanel.contains(event.target);
        var isClickInsideChatWidget = chatWidget && chatWidget.contains(event.target); // Dodano za chat widget

        if (!isClickInsideToggle && !isClickInsidePanel && !isClickInsideChatWidget && !notificationDropdownPanel.classList.contains('hidden')) {
            closeNotificationPanel();
        }
    });

    // Listeneri za tabove
    tabButtons.forEach(function(button) {
        button.addEventListener('click', function() {
            var tabName = this.dataset.tab;
            activateTab(tabName);
        });
    });

    // --- Inicijalizacija ---
     fetchUnreadCounts(); // Dohvati brojače odmah pri učitavanju stranice
     setInterval(fetchUnreadCounts, 5000); // Osvježavaj brojače svakih 5 sekundi (po potrebi podesite interval)

    // Listeneri za 'open-dm-chat-btn' dugmad (iz my_sent_offers i my_received_offers)
    const dmChatButtons = document.querySelectorAll('.open-dm-chat-btn');

    dmChatButtons.forEach(button => {
        button.addEventListener('click', function() {
            if (this.disabled) {
                console.log("Chat button is disabled.");
                return;
            }

            const conversationId = this.dataset.conversationId;
            const partnerId = this.dataset.partnerId;
            const partnerName = this.dataset.partnerName; // Dohvati ime partnera

            // Pozovi openChatWidget sa ispravnim redoslijedom i brojem parametara
            openChatWidget(conversationId, partnerName, partnerId);
            closeNotificationPanel(); // Zatvori panel obavijesti nakon otvaranja chata
        });
    });

    // Ostale funkcije za accept/reject ponude itd. možete dodati ovdje
});