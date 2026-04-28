import logging
import os
import urllib.parse

import httpx

logger = logging.getLogger(__name__)

BOT_SECRET   = os.environ.get('BOT_SECRET', '')
API_BASE     = 'https://ksburayamusic.ru/deeplistening/api'
JOURNAL_BASE = 'https://ksburayamusic.ru/deeplistening/journal.html'


async def bot_auth(telegram_id: int, tg_name: str) -> dict:
    """POST /bot-auth.php → {'token': str, 'has_entries': bool}"""
    try:
        async with httpx.AsyncClient(timeout=10) as client:
            r = await client.post(
                f'{API_BASE}/bot-auth.php',
                json={'telegram_id': telegram_id, 'name': tg_name, 'bot_secret': BOT_SECRET},
            )
            return r.json()
    except Exception as e:
        logger.error('bot_auth: %s', e)
        return {}


async def save_entry(token: str, payload: dict) -> bool:
    if not token:
        logger.error('save_entry: токен отсутствует')
        return False
    try:
        async with httpx.AsyncClient(timeout=10) as client:
            r = await client.post(
                f'{API_BASE}/entries.php',
                json=payload,
                headers={'Authorization': f'Bearer {token}'},
            )
            return r.is_success
    except Exception as e:
        logger.error('save_entry: %s', e)
        return False


async def get_reminder_users() -> list[int]:
    async with httpx.AsyncClient(timeout=10) as client:
        r = await client.post(
            f'{API_BASE}/reminder_users.php',
            json={'bot_secret': BOT_SECRET},
        )
        return list(r.json().get('chat_ids', []))


async def journal_url(telegram_id: int, tg_name: str) -> str:
    auth = await bot_auth(telegram_id, tg_name)
    token = auth.get('token', '')
    if not token:
        return JOURNAL_BASE
    name = urllib.parse.quote(tg_name or str(telegram_id))
    return f'{JOURNAL_BASE}?token={token}&name={name}'
