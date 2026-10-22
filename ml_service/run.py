"""
Entrypoint to run the SACCO ML Risk Scoring FastAPI server on port 8001.
"""

import os
import sys
import uvicorn

root_dir = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if root_dir not in sys.path:
    sys.path.insert(0, root_dir)

if __name__ == '__main__':
    print("Starting SACCO Credit Risk Scoring Service on http://127.0.0.1:8001 ...")
    uvicorn.run("ml_service.app:app", host="127.0.0.1", port=8001, reload=False, log_level="info")
