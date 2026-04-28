import logging
import os

from dotenv import load_dotenv

load_dotenv()

from telegram.ext import Application, CommandHandler, ConversationHandler

from practices import PRACTICES

logging.basicConfig(
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
    level=logging.INFO,
)
logger = logging.getLogger(__name__)

BOT_TOKEN   = os.environ['BOT_TOKEN']
WEBHOOK_URL = os.environ['WEBHOOK_URL']
PORT        = int(os.environ.get('PORT', 8080))


def _build_conversation() -> ConversationHandler:
    entry_points = []
    states       = {}
    fallbacks    = []
    for practice in PRACTICES:
        entry_points.extend(practice.get_entry_points())
        states.update(practice.get_states())
        fallbacks.extend(practice.get_fallbacks())
    return ConversationHandler(
        entry_points=entry_points,
        states=states,
        fallbacks=fallbacks,
        allow_reentry=True,
    )


async def _post_init(app: Application) -> None:
    await app.bot.set_my_description(
        description=(
            'Привет! Добро пожаловать в Звуковые Опыты. '
            'Это бот, который помогает вести дневник звуковых практик '
            'и развивать навык слухового восприятия. '
            'Жми /start чтобы начать.'
        ),
        language_code='ru',
    )


def main() -> None:
    app = Application.builder().token(BOT_TOKEN).post_init(_post_init).build()
    app.add_handler(_build_conversation())
    for practice in PRACTICES:
        practice.register_jobs(app)
    app.run_webhook(
        listen='0.0.0.0',
        port=PORT,
        url_path='webhook',
        webhook_url=f'{WEBHOOK_URL}/webhook',
    )


if __name__ == '__main__':
    main()
