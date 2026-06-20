/**
 * RMS Analyzer Worker
 *
 * Decodes audio in background, computes RMS (loudness proxy) for ReplayGain-style
 * normalization. Sends result back to main thread for caching.
 *
 * Protocol:
 *   in:  { type: 'analyze', songId: number, url: string }
 *   out: { type: 'result', songId, rms_db, peak_db } | { type: 'error', songId, error }
 */
self.addEventListener('message', async (e) => {
    const data = e.data || {};
    if (data.type !== 'analyze') return;
    const { songId, url } = data;
    try {
        const r = await fetch(url, { headers: { 'Range': 'bytes=0-2097151' } });
        const buf = await r.arrayBuffer();
        if (typeof OfflineAudioContext === 'undefined') {
            self.postMessage({ type: 'error', songId, error: 'OfflineAudioContext unavailable in worker' });
            return;
        }
        const ctx = new OfflineAudioContext(2, 44100 * 30, 44100);
        const decoded = await ctx.decodeAudioData(buf);
        const ch = decoded.getChannelData(0);
        let sumSq = 0, peak = 0;
        for (let i = 0; i < ch.length; i++) {
            const v = ch[i];
            sumSq += v * v;
            const abs = v < 0 ? -v : v;
            if (abs > peak) peak = abs;
        }
        const rms = Math.sqrt(sumSq / ch.length);
        const rms_db = 20 * Math.log10(Math.max(1e-10, rms));
        const peak_db = 20 * Math.log10(Math.max(1e-10, peak));
        self.postMessage({ type: 'result', songId, rms_db, peak_db });
    } catch (err) {
        self.postMessage({ type: 'error', songId, error: String(err && err.message || err) });
    }
});
