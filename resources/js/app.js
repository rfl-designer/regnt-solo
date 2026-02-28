import { Terminal } from '@xterm/xterm';
import { FitAddon } from '@xterm/addon-fit';
import { WebLinksAddon } from '@xterm/addon-web-links';
import '@xterm/xterm/css/xterm.css';

// Make Terminal available globally for Alpine components
window.Terminal = Terminal;
window.FitAddon = FitAddon;
window.WebLinksAddon = WebLinksAddon;

// Terminal Session Alpine Component
document.addEventListener('alpine:init', () => {
    Alpine.data('terminalSession', (config = {}) => ({
        taskId: config.taskId || null,
        sessionPrompt: config.sessionPrompt || '',
        serverUrl: config.serverUrl || 'ws://localhost:3001',
        term: null,
        fitAddon: null,
        ws: null,
        connected: false,
        reconnecting: false,
        reconnectAttempts: 0,
        maxReconnectAttempts: 5,

        init() {
            this.$nextTick(() => {
                this.initTerminal();
                this.connect();
            });

            // Handle window resize
            window.addEventListener('resize', () => this.fit());

            // Cleanup on destroy
            this.$watch('$el', () => {
                if (!document.body.contains(this.$el)) {
                    this.destroy();
                }
            });
        },

        initTerminal() {
            this.term = new Terminal({
                cursorBlink: true,
                cursorStyle: 'block',
                fontSize: 14,
                fontFamily: 'JetBrains Mono, Menlo, Monaco, monospace',
                lineHeight: 1.2,
                theme: {
                    background: '#18181b',
                    foreground: '#fafafa',
                    cursor: '#fbbf24',
                    cursorAccent: '#18181b',
                    selectionBackground: '#3f3f46',
                    black: '#27272a',
                    red: '#ef4444',
                    green: '#22c55e',
                    yellow: '#eab308',
                    blue: '#3b82f6',
                    magenta: '#a855f7',
                    cyan: '#06b6d4',
                    white: '#f4f4f5',
                    brightBlack: '#52525b',
                    brightRed: '#f87171',
                    brightGreen: '#4ade80',
                    brightYellow: '#facc15',
                    brightBlue: '#60a5fa',
                    brightMagenta: '#c084fc',
                    brightCyan: '#22d3ee',
                    brightWhite: '#ffffff',
                },
                scrollback: 10000,
                convertEol: true,
            });

            this.fitAddon = new FitAddon();
            this.term.loadAddon(this.fitAddon);
            this.term.loadAddon(new WebLinksAddon());

            const container = this.$refs.terminal;
            if (container) {
                this.term.open(container);
                this.fit();
            }

            // Handle input
            this.term.onData((data) => {
                if (this.ws && this.ws.readyState === WebSocket.OPEN) {
                    this.ws.send(JSON.stringify({ type: 'input', data: data }));
                }
            });

            // Handle resize
            this.term.onResize(({ cols, rows }) => {
                if (this.ws && this.ws.readyState === WebSocket.OPEN) {
                    this.ws.send(JSON.stringify({ type: 'resize', cols, rows }));
                }
            });
        },

        connect() {
            if (this.ws) {
                this.ws.close();
            }

            const params = new URLSearchParams({
                task: this.taskId,
                cols: this.term.cols,
                rows: this.term.rows,
            });

            if (this.sessionPrompt) {
                params.set('prompt', this.sessionPrompt);
            }

            const url = `${this.serverUrl}?${params.toString()}`;
            this.ws = new WebSocket(url);

            this.ws.onopen = () => {
                this.connected = true;
                this.reconnecting = false;
                this.reconnectAttempts = 0;
                this.term.write('\x1b[32m[Conectado ao terminal]\x1b[0m\r\n');
            };

            this.ws.onmessage = (event) => {
                try {
                    const msg = JSON.parse(event.data);

                    switch (msg.type) {
                        case 'output':
                            this.term.write(msg.data);
                            break;
                        case 'system':
                            this.term.write(`\x1b[33m${msg.data}\x1b[0m`);
                            break;
                        case 'exit':
                            this.term.write(`\r\n\x1b[31m[Processo encerrado (código: ${msg.code})]\x1b[0m\r\n`);
                            this.connected = false;
                            break;
                        case 'pong':
                            // Heartbeat response
                            break;
                    }
                } catch (err) {
                    // Raw data fallback
                    this.term.write(event.data);
                }
            };

            this.ws.onclose = () => {
                this.connected = false;
                if (!this.reconnecting && this.reconnectAttempts < this.maxReconnectAttempts) {
                    this.reconnecting = true;
                    this.reconnectAttempts++;
                    this.term.write(`\r\n\x1b[33m[Reconectando... (${this.reconnectAttempts}/${this.maxReconnectAttempts})]\x1b[0m\r\n`);
                    setTimeout(() => this.connect(), 2000);
                } else if (this.reconnectAttempts >= this.maxReconnectAttempts) {
                    this.term.write('\r\n\x1b[31m[Conexão perdida. Recarregue a página para reconectar.]\x1b[0m\r\n');
                }
            };

            this.ws.onerror = (err) => {
                console.error('[Terminal] WebSocket error:', err);
                this.term.write('\r\n\x1b[31m[Erro de conexão]\x1b[0m\r\n');
            };

            // Heartbeat
            this.heartbeatInterval = setInterval(() => {
                if (this.ws && this.ws.readyState === WebSocket.OPEN) {
                    this.ws.send(JSON.stringify({ type: 'ping' }));
                }
            }, 30000);
        },

        fit() {
            if (this.fitAddon && this.term) {
                try {
                    this.fitAddon.fit();
                } catch (e) {
                    // Ignore fit errors
                }
            }
        },

        sendCommand(command) {
            if (this.ws && this.ws.readyState === WebSocket.OPEN) {
                this.ws.send(JSON.stringify({ type: 'input', data: command + '\n' }));
            }
        },

        clear() {
            if (this.term) {
                this.term.clear();
            }
        },

        destroy() {
            if (this.heartbeatInterval) {
                clearInterval(this.heartbeatInterval);
            }
            if (this.ws) {
                this.ws.close();
            }
            if (this.term) {
                this.term.dispose();
            }
        },
    }));
});
