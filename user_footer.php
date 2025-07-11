<footer class="footer">
    <div id="chat-widget" class="chat-widget hidden"> 
        <div class="chat-header">
            <span id="chat-partner-name">Chat s korisnikom...</span>
            <div class="chat-actions">
                <button id="chat-minimize-btn" class="chat-btn">&#x2212;</button>
                <button id="chat-close-btn" class="chat-btn">&times;</button>
            </div>
        </div>
        <div class="chat-body" id="chat-messages-container">
            <div class="message received">
                <p>Pozdrav! Kako vam mogu pomoći?</p>
                <span class="timestamp">10:00 AM</span>
            </div>
            <div class="message sent">
                <p>Imam pitanje u vezi oglasa.</p>
                <span class="timestamp">10:01 AM</span>
            </div>
        </div>
        <div class="chat-footer">
            <textarea id="chat-message-input" placeholder="Upišite poruku..."></textarea>
            <button id="chat-send-btn">Pošalji</button>
        </div>
    </div>

    <div class="footer-content">
        <p>&copy; 2024 Vaša Kompanija. Sva prava zadržana.</p>
        <nav>
            <ul>
                <li><a href="#">Politika privatnosti</a></li>
                <li><a href="#">Uslovi korištenja</a></li>
            </ul>
        </nav>
    </div>

    <script src="/public/js/notification_manager.js"></script>
</footer>